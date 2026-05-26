import SwiftUI

struct BiometricLockView: View {
    @EnvironmentObject var auth: AuthService
    @State private var attempting = false
    @State private var autoAttempted = false

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

                VStack(spacing: 12) {
                    Image(systemName: iconName)
                        .font(.system(size: 72, weight: .bold))
                        .foregroundStyle(.white)
                    Text("PTSA Board")
                        .font(.system(size: 28, weight: .bold, design: .rounded))
                        .foregroundStyle(.white)
                    if let p = auth.profile {
                        Text(p.displayName)
                            .foregroundStyle(.white.opacity(0.9))
                    }
                }

                Spacer()

                VStack(spacing: 14) {
                    Button {
                        Task { await unlock() }
                    } label: {
                        HStack(spacing: 12) {
                            if attempting {
                                ProgressView().tint(.accentColor)
                            } else {
                                Image(systemName: iconName)
                            }
                            Text(attempting ? "Checking…" : "Try \(label) Again")
                                .fontWeight(.semibold)
                        }
                        .frame(maxWidth: .infinity, minHeight: 52)
                        .background(.white)
                        .foregroundStyle(Color.accentColor)
                        .clipShape(RoundedRectangle(cornerRadius: 14, style: .continuous))
                    }
                    .disabled(attempting)

                    Button("Sign in with Microsoft") {
                        auth.useMicrosoftSignInFromLock()
                    }
                    .foregroundStyle(.white.opacity(0.9))

                    if let err = auth.lastError {
                        Text(err)
                            .font(.caption)
                            .multilineTextAlignment(.center)
                            .padding(10)
                            .background(.white.opacity(0.18))
                            .foregroundStyle(.white)
                            .clipShape(RoundedRectangle(cornerRadius: 10, style: .continuous))
                    }
                }
                .padding(.horizontal, 24)
                .padding(.bottom, 50)
            }
        }
        .task {
            guard !autoAttempted else { return }
            autoAttempted = true
            await unlock()
        }
    }

    @MainActor
    private func unlock() async {
        guard !attempting else { return }
        attempting = true
        await auth.unlockWithBiometrics()
        attempting = false
    }

    private var iconName: String {
        switch BiometricService.available {
        case .faceID:  return "faceid"
        case .touchID: return "touchid"
        case .opticID: return "opticid"
        case .none:    return "lock.fill"
        }
    }

    private var label: String {
        switch BiometricService.available {
        case .faceID:  return "Face ID"
        case .touchID: return "Touch ID"
        case .opticID: return "Optic ID"
        case .none:    return "passcode"
        }
    }
}
