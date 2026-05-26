import SwiftUI

struct SettingsView: View {
    @EnvironmentObject var auth: AuthService
    @EnvironmentObject var theme: ThemeManager
    @Environment(\.dismiss) private var dismiss

    @State private var info: String?
    @State private var error: String?
    @State private var working = false
    @State private var showAuctionSheet = false

    var body: some View {
        NavigationStack {
            Form {
                Section {
                    HStack(spacing: 14) {
                        AvatarView(profile: auth.profile, size: 56)
                        VStack(alignment: .leading, spacing: 2) {
                            Text(auth.profile?.displayName ?? "Signed in")
                                .font(.headline)
                            if let p = auth.profile {
                                Text(p.email).font(.subheadline).foregroundStyle(.secondary)
                                if let title = p.jobTitle, !title.isEmpty {
                                    Text(title).font(.caption).foregroundStyle(.secondary)
                                }
                            }
                        }
                    }
                }

                Section("Account") {
                    Button {
                        Task { await resetSelfPw() }
                    } label: { Label("Reset my WordPress password", systemImage: "key.fill") }
                    .disabled(working)

                    Button {
                        Task { await auth.signOut(); dismiss() }
                    } label: {
                        Label("Sign out", systemImage: "rectangle.portrait.and.arrow.right")
                            .foregroundStyle(.red)
                    }
                }

                Section("Notifications") {
                    Button {
                        showAuctionSheet = true
                    } label: {
                        Label("Email auction items list…", systemImage: "envelope.badge.fill")
                    }
                    NavigationLink {
                        EmailTriggersHelpView()
                    } label: {
                        Label("Other email triggers", systemImage: "paperplane")
                    }
                }

                Section("Appearance") {
                    Picker("Theme", selection: Binding(
                        get: { theme.choice },
                        set: { theme.choice = $0 }
                    )) {
                        ForEach(ThemeManager.AppearanceChoice.allCases) { Text($0.label).tag($0) }
                    }
                }

                Section("About") {
                    HStack { Text("Version"); Spacer(); Text(appVersion).foregroundStyle(.secondary) }
                    HStack { Text("WordPress"); Spacer(); Text(AppConfig.wordpressBaseURL.host ?? "").foregroundStyle(.secondary) }
                    HStack { Text("Calendar"); Spacer(); Text(AppConfig.sharedCalendarMailbox).foregroundStyle(.secondary) }
                }
            }
            .navigationTitle("Settings")
            .toolbar {
                ToolbarItem(placement: .topBarTrailing) { Button("Done") { dismiss() } }
            }
            .sheet(isPresented: $showAuctionSheet) {
                AuctionEmailSheet().environmentObject(auth)
            }
            .overlay(alignment: .top) {
                VStack {
                    if let error { ErrorBanner(message: error) { self.error = nil } }
                    if let info {
                        Text(info)
                            .font(.footnote)
                            .padding(10)
                            .background(.green.opacity(0.9))
                            .foregroundStyle(.white)
                            .clipShape(RoundedRectangle(cornerRadius: 10, style: .continuous))
                            .padding(.horizontal)
                    }
                }
            }
            .overlay { if working { ProgressView().controlSize(.large) } }
        }
    }

    private var appVersion: String {
        let v = Bundle.main.infoDictionary?["CFBundleShortVersionString"] as? String ?? "1.0"
        let b = Bundle.main.infoDictionary?["CFBundleVersion"] as? String ?? "1"
        return "\(v) (\(b))"
    }

    @MainActor
    private func resetSelfPw() async {
        working = true; defer { working = false }
        do {
            try await WordPressService.shared.triggerSelfPasswordReset()
            info = "Reset email sent to \(auth.profile?.email ?? "you")."
            Task { try? await Task.sleep(nanoseconds: 3_000_000_000); info = nil }
        } catch {
            self.error = error.localizedDescription
        }
    }
}

// MARK: - Email triggers help

private struct EmailTriggersHelpView: View {
    var body: some View {
        List {
            Section {
                Text("This screen lets you trigger common bulk emails without opening WordPress.")
                    .font(.subheadline)
            }
            Section("Available triggers") {
                Label("Auction items bulletin", systemImage: "tag.fill")
                Label("Order receipt resend (from each order)", systemImage: "envelope.arrow.triangle.branch")
                Label("Password reset (from any user)", systemImage: "key.fill")
            }
            Section {
                Text("Need a new trigger? Add it in `PTSAService` under the WordPress plugin and we'll surface it here.")
                    .font(.caption)
                    .foregroundStyle(.secondary)
            }
        }
        .navigationTitle("Email triggers")
        .navigationBarTitleDisplayMode(.inline)
    }
}
