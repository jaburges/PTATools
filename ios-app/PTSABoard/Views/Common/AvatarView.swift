import SwiftUI

struct AvatarView: View {
    let profile: UserProfile?
    var size: CGFloat = 32

    var body: some View {
        ZStack {
            Circle()
                .fill(LinearGradient(
                    colors: [.accentColor.opacity(0.9), .accentColor.opacity(0.6)],
                    startPoint: .topLeading, endPoint: .bottomTrailing
                ))
            Text(profile?.initials ?? "?")
                .font(.system(size: size * 0.42, weight: .bold, design: .rounded))
                .foregroundStyle(.white)
        }
        .frame(width: size, height: size)
        .overlay(
            Circle().stroke(.white.opacity(0.5), lineWidth: 1.5)
        )
        .shadow(color: .black.opacity(0.1), radius: 2, x: 0, y: 1)
    }
}
