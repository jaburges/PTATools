import Foundation
import SwiftUI
import MSAL

/// Auth state machine for the app.
enum AuthState: Equatable {
    case loading
    case signedOut
    case locked        // we have a valid cached account, but biometrics not yet passed this session
    case signedIn
}

@MainActor
final class AuthService: ObservableObject {

    // MARK: - Published state

    @Published private(set) var state: AuthState = .loading
    @Published private(set) var profile: UserProfile?
    @Published private(set) var lastError: String?
    @Published private(set) var signInExpiredAlert = false

    // MARK: - MSAL

    private var msal: MSALPublicClientApplication?
    private var account: MSALAccount?
    private var cachedAccessToken: String?
    private var cachedIdToken: String?
    private var cachedTokenExpiry: Date?

    private let biometricEnrolledKey = "biometricEnrolled"

    // MARK: - Bootstrap

    init() {
        do {
            let authority = try MSALAADAuthority(url: URL(string: AppConfig.entraAuthority)!)
            let config = MSALPublicClientApplicationConfig(
                clientId: AppConfig.entraClientId,
                redirectUri: AppConfig.entraRedirectUri,
                authority: authority
            )
            config.knownAuthorities = [authority]
            self.msal = try MSALPublicClientApplication(configuration: config)
            print("[MSAL] init OK — clientId=\(AppConfig.entraClientId) tenant=\(AppConfig.entraTenantId) redirect=\(AppConfig.entraRedirectUri)")
        } catch {
            let ns = error as NSError
            self.lastError = "MSAL init failed: \(error.localizedDescription)"
            print("[MSAL] init FAILED domain=\(ns.domain) code=\(ns.code) userInfo=\(ns.userInfo)")
            self.msal = nil
        }
    }

    // MARK: - Public API

    /// Called on app launch to figure out where the user should land.
    /// - If an MSAL account exists AND biometrics are available, route through `locked`.
    /// - Else, if an account exists, attempt silent refresh and go `signedIn`.
    /// - Else go `signedOut`.
    func restoreSession() async {
        guard let msal else { state = .signedOut; return }

        let accounts = (try? msal.allAccounts()) ?? []
        guard let acct = accounts.first else {
            state = .signedOut
            return
        }
        self.account = acct

        if let cachedJson = KeychainService.getData("profile.json"),
           let profile = try? JSONDecoder().decode(UserProfile.self, from: cachedJson) {
            self.profile = profile
        }

        if BiometricService.available != .none,
           KeychainService.get("biometricEnrolled") == "1" {
            state = .locked
        } else {
            await refreshSilent(enrollBiometricsIfNeeded: true)
        }
    }

    /// First-time / explicit interactive sign in.
    func signIn(presenter: UIViewController) async {
        guard let msal else { lastError = "MSAL not initialized"; return }
        lastError = nil
        signInExpiredAlert = false

        let webParams = MSALWebviewParameters(authPresentationViewController: presenter)
        let params = MSALInteractiveTokenParameters(
            scopes: AppConfig.graphScopes,
            webviewParameters: webParams
        )
        params.promptType = .selectAccount

        do {
            let result: MSALResult = try await withCheckedThrowingContinuation { cont in
                msal.acquireToken(with: params) { res, err in
                    if let res { cont.resume(returning: res) }
                    else { cont.resume(throwing: err ?? NSError(domain: "MSAL", code: -1)) }
                }
            }

            try await handleSuccessfulLogin(result: result, persistBiometric: true)
        } catch {
            let ns = error as NSError
            let internalCode = ns.userInfo["MSALInternalErrorCodeKey"] as? Int
            let oauthError = ns.userInfo["MSALOAuthErrorKey"] as? String
            let oauthSub = ns.userInfo["MSALOAuthSubErrorKey"] as? String
            let serverDesc = ns.userInfo["MSALErrorDescriptionKey"] as? String
            let correlationId = ns.userInfo["MSALCorrelationIDKey"] as? String

            var detail = "domain=\(ns.domain) code=\(ns.code)"
            if let internalCode { detail += " internalCode=\(internalCode)" }
            if let oauthError { detail += " oauthError=\(oauthError)" }
            if let oauthSub { detail += " oauthSubError=\(oauthSub)" }
            if let correlationId { detail += " correlationId=\(correlationId)" }
            if let serverDesc { detail += " serverDesc=\(serverDesc)" }
            detail += " localized=\(error.localizedDescription)"

            print("[MSAL] signIn FAILED \(detail)")
            print("[MSAL] full userInfo=\(ns.userInfo)")
            self.lastError = "Sign-in failed (code \(ns.code)\(internalCode.map { ".\($0)" } ?? "")): \(error.localizedDescription)"
        }
    }

    /// Biometric unlock after the app has been killed/foregrounded.
    @discardableResult
    func unlockWithBiometrics() async -> Bool {
        lastError = nil
        do {
            try await BiometricService.authenticate(reason: "Unlock PTSA Board")
            return await refreshSilentAfterLocalUnlock()
        } catch {
            self.lastError = "Face ID wasn't completed. Try again or sign in with Microsoft."
            return false
        }
    }

    /// The user chose to use Microsoft sign-in from the local lock screen.
    func useMicrosoftSignInFromLock() {
        lastError = nil
        state = .signedOut
    }

    func dismissSignInExpiredAlert() {
        signInExpiredAlert = false
    }

    /// Sign out and forget cached tokens / biometric enrolment.
    func signOut() async {
        if let msal, let account {
            try? msal.remove(account)
        }
        self.account = nil
        self.profile = nil
        self.cachedAccessToken = nil
        self.cachedIdToken = nil
        self.cachedTokenExpiry = nil
        KeychainService.clearAll()
        state = .signedOut
    }

    /// Return a fresh access token for Microsoft Graph, refreshing silently if needed.
    func graphAccessToken() async throws -> String {
        if let token = cachedAccessToken,
           let exp = cachedTokenExpiry,
           exp.timeIntervalSinceNow > 60 {
            return token
        }
        _ = try await acquireTokenSilent()
        guard let token = cachedAccessToken else {
            throw NSError(domain: "AuthService", code: -30, userInfo: [
                NSLocalizedDescriptionKey: "No Graph access token after silent refresh"
            ])
        }
        return token
    }

    /// Return a fresh **id token** for our own backend (`/wp-json/ptsa/v1/*`).
    /// The id token's `aud` is our Entra client_id — it's the right credential
    /// for endpoints we control. The Graph access token, by contrast, is opaque
    /// to anyone except graph.microsoft.com.
    func wordpressIdToken() async throws -> String {
        if let token = cachedIdToken,
           let exp = cachedTokenExpiry,
           exp.timeIntervalSinceNow > 60 {
            return token
        }
        _ = try await acquireTokenSilent()
        guard let token = cachedIdToken else {
            throw NSError(domain: "AuthService", code: -31, userInfo: [
                NSLocalizedDescriptionKey: "No id token after silent refresh"
            ])
        }
        return token
    }

    // MARK: - Internals

    private func refreshSilent(enrollBiometricsIfNeeded: Bool = false) async {
        do {
            _ = try await acquireTokenSilent()
            if enrollBiometricsIfNeeded {
                await autoEnrollBiometricsIfAppropriate()
            }
            state = .signedIn
        } catch {
            self.lastError = "Could not refresh session, please sign in again."
            state = .signedOut
        }
    }

    private func refreshSilentAfterLocalUnlock() async -> Bool {
        do {
            _ = try await acquireTokenSilent()
            state = .signedIn
            return true
        } catch {
            cachedAccessToken = nil
            cachedIdToken = nil
            cachedTokenExpiry = nil
            lastError = nil
            signInExpiredAlert = true
            state = .signedOut
            return false
        }
    }

    private func acquireTokenSilent() async throws -> String {
        guard let msal, let account else {
            throw NSError(domain: "AuthService", code: -10, userInfo: [
                NSLocalizedDescriptionKey: "No MSAL account"
            ])
        }
        let authority = try MSALAADAuthority(url: URL(string: AppConfig.entraAuthority)!)
        let params = MSALSilentTokenParameters(scopes: AppConfig.graphScopes, account: account)
        params.authority = authority

        let result: MSALResult = try await withCheckedThrowingContinuation { cont in
            msal.acquireTokenSilent(with: params) { res, err in
                if let res { cont.resume(returning: res) }
                else { cont.resume(throwing: err ?? NSError(domain: "MSAL", code: -2)) }
            }
        }

        try await handleSuccessfulLogin(result: result, persistBiometric: false)
        return result.accessToken
    }

    private func handleSuccessfulLogin(result: MSALResult, persistBiometric: Bool) async throws {
        let email = result.account.username ?? ""
        guard email.lowercased().hasSuffix("@\(AppConfig.allowedEmailDomain.lowercased())") else {
            // Reject non-PTSA accounts
            try? msal?.remove(result.account)
            throw NSError(domain: "AuthService", code: -20, userInfo: [
                NSLocalizedDescriptionKey: "Only @\(AppConfig.allowedEmailDomain) accounts may use this app."
            ])
        }

        self.account = result.account
        self.cachedAccessToken = result.accessToken
        self.cachedIdToken = result.idToken
        self.cachedTokenExpiry = result.expiresOn

        // Fetch /me to populate profile
        let me = try await GraphService.shared.fetchMe(accessToken: result.accessToken)
        self.profile = me
        if let data = try? JSONEncoder().encode(me) {
            KeychainService.setData(data, for: "profile.json")
        }

        if persistBiometric {
            await autoEnrollBiometricsIfAppropriate()
        }

        state = .signedIn
    }

    private func autoEnrollBiometricsIfAppropriate() async {
        let available = BiometricService.available
        print("[Auth] biometric enrollment check available=\(available) enrolled=\(KeychainService.get(biometricEnrolledKey) ?? "nil")")
        guard available != .none else { return }
        guard KeychainService.get(biometricEnrolledKey) != "1" else { return }

        do {
            try await BiometricService.authenticateBiometricsOnly(reason: "Enable Face ID for PTSA Board")
            KeychainService.set("1", for: biometricEnrolledKey)
            print("[Auth] biometric enrollment enabled")
        } catch {
            // User cancellation should not block first-run access. The app will
            // continue to use Microsoft sign-in until biometrics are enrolled.
            print("[Auth] biometric enrollment skipped: \(error.localizedDescription)")
        }
    }
}

/// Convenience for grabbing the current root view controller for MSAL.
@MainActor
enum TopController {
    static func current() -> UIViewController? {
        guard let scene = UIApplication.shared.connectedScenes
            .compactMap({ $0 as? UIWindowScene })
            .first(where: { $0.activationState == .foregroundActive }) ?? UIApplication.shared.connectedScenes.compactMap({ $0 as? UIWindowScene }).first,
              let window = scene.windows.first(where: { $0.isKeyWindow }) ?? scene.windows.first
        else { return nil }

        var vc = window.rootViewController
        while let presented = vc?.presentedViewController {
            vc = presented
        }
        return vc
    }
}
