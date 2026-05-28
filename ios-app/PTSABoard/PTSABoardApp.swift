import SwiftUI
import MSAL

@main
struct PTSABoardApp: App {

    @StateObject private var auth = AuthService()
    @StateObject private var theme = ThemeManager()
    @Environment(\.scenePhase) private var scenePhase

    var body: some Scene {
        WindowGroup {
            RootView()
                .environmentObject(auth)
                .environmentObject(theme)
                .preferredColorScheme(theme.preferred)
                .tint(.accentColor)
                .onOpenURL { url in
                    let handled = MSALPublicClientApplication.handleMSALResponse(
                        url,
                        sourceApplication: nil
                    )
                    print("[MSAL] onOpenURL handled=\(handled) url=\(url.absoluteString)")
                }
                .task {
                    AuthDelegate.shared.graphTokenProvider = { [weak auth] in
                        guard let auth else { throw APIError.notConfigured("AuthService") }
                        return try await auth.graphAccessToken()
                    }
                    AuthDelegate.shared.wordpressTokenProvider = { [weak auth] in
                        guard let auth else { throw APIError.notConfigured("AuthService") }
                        return try await auth.wordpressIdToken()
                    }
                    await auth.restoreSession()
                }
                .onChange(of: scenePhase) { _, phase in
                    switch phase {
                    case .background:
                        auth.appDidEnterBackground()
                    case .active:
                        auth.appDidBecomeActive()
                    default:
                        break
                    }
                }
        }
    }
}
