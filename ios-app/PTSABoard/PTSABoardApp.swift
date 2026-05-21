import SwiftUI
import MSAL

@main
struct PTSABoardApp: App {

    @StateObject private var auth = AuthService()
    @StateObject private var theme = ThemeManager()

    var body: some Scene {
        WindowGroup {
            RootView()
                .environmentObject(auth)
                .environmentObject(theme)
                .preferredColorScheme(theme.preferred)
                .tint(.accentColor)
                .onOpenURL { url in
                    MSALPublicClientApplication.handleMSALResponse(
                        url,
                        sourceApplication: nil
                    )
                }
                .task {
                    AuthDelegate.shared.tokenProvider = { [weak auth] in
                        guard let auth else {
                            throw APIError.notConfigured("AuthService")
                        }
                        return try await auth.graphAccessToken()
                    }
                    await auth.restoreSession()
                }
        }
    }
}
