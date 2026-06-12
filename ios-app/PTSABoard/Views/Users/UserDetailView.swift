import SwiftUI

struct UserDetailView: View {
    let userId: Int

    @State private var user: WPUser?
    @State private var error: String?
    @State private var info: String?
    @State private var working = false
    @State private var loading = true
    @State private var showRoleEditor = false
    @State private var roleDraft: Set<String> = []
    @State private var availableRoles: [WPRole] = []

    var body: some View {
        Group {
            if loading && user == nil {
                ProgressView("Loading profile…")
                    .frame(maxWidth: .infinity, maxHeight: .infinity)
            } else if let user {
                profileForm(user)
            } else {
                EmptyStateView(
                    systemImage: "person.crop.circle.badge.exclamationmark",
                    title: "Profile unavailable",
                    message: error ?? "Could not load this user."
                )
            }
        }
        .navigationTitle(user?.displayName ?? "User")
        .navigationBarTitleDisplayMode(.inline)
        .task(id: userId) { await loadUser() }
        .overlay { if working { ProgressView().controlSize(.large) } }
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
            }.padding(.top, 4)
        }
        .sheet(isPresented: $showRoleEditor) {
            NavigationStack {
                List {
                    if availableRoles.isEmpty {
                        ProgressView("Loading roles")
                    }
                    ForEach(availableRoles) { role in
                        Button {
                            if roleDraft.contains(role.slug) { roleDraft.remove(role.slug) }
                            else { roleDraft.insert(role.slug) }
                        } label: {
                            HStack {
                                VStack(alignment: .leading, spacing: 2) {
                                    Text(role.name)
                                    Text(role.slug)
                                        .font(.caption)
                                        .foregroundStyle(.secondary)
                                }
                                Spacer()
                                if roleDraft.contains(role.slug) {
                                    Image(systemName: "checkmark").foregroundStyle(Color.accentColor)
                                }
                            }
                        }
                        .buttonStyle(.plain)
                    }
                }
                .navigationTitle("Roles")
                .navigationBarTitleDisplayMode(.inline)
                .toolbar {
                    ToolbarItem(placement: .cancellationAction) { Button("Cancel") { showRoleEditor = false } }
                    ToolbarItem(placement: .confirmationAction) {
                        Button("Save") {
                            Task { showRoleEditor = false; await saveRoles() }
                        }
                    }
                }
            }
        }
    }

    @ViewBuilder
    private func profileForm(_ user: WPUser) -> some View {
        Form {
            Section {
                HStack(alignment: .center, spacing: 16) {
                    avatar(for: user)
                    VStack(alignment: .leading, spacing: 4) {
                        Text(user.displayName).font(.title3.weight(.bold))
                        if let e = user.email, !e.isEmpty {
                            Text(e).font(.subheadline).foregroundStyle(.secondary)
                        }
                        if let u = user.username, !u.isEmpty {
                            Text("@\(u)").font(.caption).foregroundStyle(.secondary)
                        }
                    }
                }
            }

            Section("Roles") {
                if let roles = user.roles, !roles.isEmpty {
                    ForEach(roles, id: \.self) { Text($0.replacingOccurrences(of: "_", with: " ").capitalized) }
                } else {
                    Text("No roles").foregroundStyle(.secondary)
                }
                Button {
                    roleDraft = Set(user.roles ?? [])
                    showRoleEditor = true
                    Task { await loadRoles() }
                } label: {
                    Label("Edit roles", systemImage: "person.crop.circle.badge.checkmark")
                }
            }

            Section("Actions") {
                if let e = user.email, !e.isEmpty {
                    Button {
                        UIApplication.shared.open(URL(string: "mailto:\(e)")!)
                    } label: { Label("Email", systemImage: "envelope") }
                }
                Button {
                    Task { await sendReset(for: user) }
                } label: { Label("Send password reset", systemImage: "key.fill") }
                .disabled(working || user.email == nil)
            }
        }
    }

    @MainActor
    private func loadUser() async {
        loading = true
        defer { loading = false }
        do {
            user = try await WordPressService.shared.fetchUser(userId)
            error = nil
        } catch {
            self.error = error.localizedDescription
        }
    }

    @MainActor
    private func loadRoles() async {
        do {
            availableRoles = try await WordPressService.shared.roles()
        } catch {
            self.error = error.localizedDescription
            if availableRoles.isEmpty {
                availableRoles = [
                    WPRole(slug: "administrator", name: "Administrator"),
                    WPRole(slug: "editor", name: "Editor"),
                    WPRole(slug: "shop_manager", name: "Shop Manager"),
                    WPRole(slug: "subscriber", name: "Subscriber"),
                    WPRole(slug: "customer", name: "Customer")
                ]
            }
        }
    }

    @ViewBuilder private func avatar(for user: WPUser) -> some View {
        ZStack {
            Circle().fill(Color.accentColor.opacity(0.18))
            if let url = user.avatarURL {
                AsyncImage(url: url) { image in
                    image.resizable().scaledToFill()
                } placeholder: { Text(initials(for: user)).font(.title2.bold()).foregroundStyle(Color.accentColor) }
                .clipShape(Circle())
            } else {
                Text(initials(for: user)).font(.title2.bold()).foregroundStyle(Color.accentColor)
            }
        }
        .frame(width: 64, height: 64)
    }

    private func initials(for user: WPUser) -> String {
        let parts = user.displayName.split(separator: " ").prefix(2)
        return parts.compactMap { $0.first }.map { String($0) }.joined().uppercased()
    }

    @MainActor
    private func sendReset(for user: WPUser) async {
        guard let email = user.email else { return }
        working = true; defer { working = false }
        do {
            try await WordPressService.shared.triggerPasswordReset(forEmail: email)
            info = "Reset email sent to \(email)."
            Task { try? await Task.sleep(nanoseconds: 3_000_000_000); info = nil }
        } catch {
            self.error = error.localizedDescription
        }
    }

    @MainActor
    private func saveRoles() async {
        working = true; defer { working = false }
        do {
            user = try await WordPressService.shared.updateUserRoles(userId, roles: Array(roleDraft))
            info = "Roles updated."
            Task { try? await Task.sleep(nanoseconds: 2_500_000_000); info = nil }
        } catch {
            self.error = error.localizedDescription
        }
    }
}
