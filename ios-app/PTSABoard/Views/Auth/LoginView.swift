import SwiftUI

struct LoginView: View {
    @EnvironmentObject var auth: AuthService
    @State private var signingIn = false

    var body: some View {
        ZStack {
            LinearGradient(
                colors: [Color.accentColor.opacity(0.85), Color.accentColor.opacity(0.45)],
                startPoint: .topLeading,
                endPoint: .bottomTrailing
            )
            .ignoresSafeArea()

            VStack(spacing: 28) {
                Spacer()

                VStack(spacing: 8) {
                    Image(systemName: "graduationcap.fill")
                        .font(.system(size: 64, weight: .bold))
                        .foregroundStyle(.white)
                    Text("Wilder PTSA")
                        .font(.system(size: 34, weight: .bold, design: .rounded))
                        .foregroundStyle(.white)
                    Text("Board Console")
                        .font(.title3)
                        .foregroundStyle(.white.opacity(0.85))
                }

                Spacer()

                VStack(spacing: 16) {
                    Button {
                        Task {
                            signingIn = true
                            if let vc = TopController.current() {
                                await auth.signIn(presenter: vc)
                            }
                            signingIn = false
                        }
                    } label: {
                        HStack(spacing: 12) {
                            if signingIn {
                                ProgressView()
                                    .tint(.accentColor)
                            } else {
                                Image(systemName: "lock.shield.fill")
                                    .font(.title3)
                            }
                            Text(signingIn ? "Signing in…" : "Sign in with Microsoft")
                                .fontWeight(.semibold)
                        }
                        .frame(maxWidth: .infinity, minHeight: 52)
                        .background(.white)
                        .foregroundStyle(Color.accentColor)
                        .clipShape(RoundedRectangle(cornerRadius: 14, style: .continuous))
                    }
                    .disabled(signingIn)

                    Text("Use your **@\(AppConfig.allowedEmailDomain)** account")
                        .font(.footnote)
                        .foregroundStyle(.white.opacity(0.85))

                    if let err = auth.lastError {
                        Text(err)
                            .font(.caption)
                            .multilineTextAlignment(.center)
                            .padding(10)
                            .background(.red.opacity(0.85))
                            .foregroundStyle(.white)
                            .clipShape(RoundedRectangle(cornerRadius: 10, style: .continuous))
                            .padding(.horizontal, 8)
                    }
                }
                .padding(.horizontal, 24)
                .padding(.bottom, 40)
            }
        }
    }
}

#Preview {
    LoginView().environmentObject(AuthService())
}
