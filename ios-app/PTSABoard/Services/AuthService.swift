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

    // MARK: - MSAL

    private var msal: MSALPublicClientApplication?
    private var account: MSALAccount?
    private var cachedAccessToken: String?
    private var cachedTokenExpiry: Date?

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
        } catch {
            self.lastError = "MSAL init failed: \(error.localizedDescription)"
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
            await refreshSilent()
        }
    }

    /// First-time / explicit interactive sign in.
    func signIn(presenter: UIViewController) async {
        guard let msal else { lastError = "MSAL not initialized"; return }

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
            self.lastError = "Sign-in failed: \(error.localizedDescription)"
        }
    }

    /// Biometric unlock after the app has been killed/foregrounded.
    func unlockWithBiometrics() async {
        do {
            try await BiometricService.authenticate(reason: "Unlock PTSA Board")
            await refreshSilent()
        } catch {
            self.lastError = "Biometric unlock failed: \(error.localizedDescription)"
        }
    }

    /// Sign out and forget cached tokens / biometric enrolment.
    func signOut() async {
        if let msal, let account {
            try? msal.remove(account)
        }
        self.account = nil
        self.profile = nil
        self.cachedAccessToken = nil
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
        return try await acquireTokenSilent()
    }

    // MARK: - Internals

    private func refreshSilent() async {
        do {
            _ = try await acquireTokenSilent()
            state = .signedIn
        } catch {
            self.lastError = "Could not refresh session, please sign in again."
            state = .signedOut
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
        self.cachedTokenExpiry = result.expiresOn

        // Fetch /me to populate profile
        let me = try await GraphService.shared.fetchMe(accessToken: result.accessToken)
        self.profile = me
        if let data = try? JSONEncoder().encode(me) {
            KeychainService.setData(data, for: "profile.json")
        }

        if persistBiometric, BiometricService.available != .none {
            KeychainService.set("1", for: "biometricEnrolled")
        }

        state = .signedIn
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
