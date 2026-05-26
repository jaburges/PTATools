import SwiftUI

struct RootView: View {
    @EnvironmentObject var auth: AuthService

    var body: some View {
        ZStack {
            switch auth.state {
            case .loading:
                SplashView()
            case .signedOut:
                LoginView()
                    .transition(.opacity)
            case .signedIn:
                MainTabView()
                    .transition(.opacity)
            case .locked:
                BiometricLockView()
                    .transition(.opacity)
            }
        }
        .animation(.easeInOut(duration: 0.25), value: auth.state)
        .alert(
            "Sign-in expired",
            isPresented: Binding(
                get: { auth.signInExpiredAlert },
                set: { if !$0 { auth.dismissSignInExpiredAlert() } }
            )
        ) {
            Button("Sign in") {
                Task {
                    auth.dismissSignInExpiredAlert()
                    if let vc = TopController.current() {
                        await auth.signIn(presenter: vc)
                    }
                }
            }
            Button("Cancel", role: .cancel) {
                auth.dismissSignInExpiredAlert()
            }
        } message: {
            Text("You need to sign in again to continue.")
        }
    }
}

private struct SplashView: View {
    var body: some View {
        ZStack {
            LinearGradient(
                colors: [.accentColor.opacity(0.15), .accentColor.opacity(0.4)],
                startPoint: .topLeading,
                endPoint: .bottomTrailing
            )
            .ignoresSafeArea()

            VStack(spacing: 20) {
                Image(systemName: "graduationcap.fill")
                    .font(.system(size: 56, weight: .bold))
                    .foregroundStyle(.white)
                ProgressView()
                    .tint(.white)
            }
        }
    }
}
