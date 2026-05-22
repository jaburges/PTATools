import SwiftUI

struct UsersView: View {
    @State private var users: [WPUser] = []
    @State private var search = ""
    @State private var loading = false
    @State private var error: String?

    var body: some View {
        Group {
            if users.isEmpty && !loading {
                EmptyStateView(
                    systemImage: "person.2.fill",
                    title: "No users",
                    message: error ?? "Search by name, username or email."
                )
            } else {
                List {
                    ForEach(users) { user in
                        NavigationLink(value: user) {
                            UserRow(user: user)
                        }
                    }
                }
                .listStyle(.insetGrouped)
            }
        }
        .navigationTitle("Users")
        .searchable(text: $search, prompt: "Search users")
        .navigationDestination(for: WPUser.self) { UserDetailView(user: $0) }
        .refreshable { await load() }
        .task { await load() }
        .onChange(of: search) {
            Task {
                try? await Task.sleep(nanoseconds: 350_000_000)
                if !Task.isCancelled { await load() }
            }
        }
        .overlay(alignment: .top) {
            if let error {
                ErrorBanner(message: error) { self.error = nil }.padding(.top, 4)
            }
        }
        .overlay {
            if loading && users.isEmpty { ProgressView().controlSize(.large) }
        }
    }

    @MainActor
    private func load() async {
        loading = true; defer { loading = false }
        do {
            users = try await WordPressService.shared.searchUsers(search)
            error = nil
        } catch {
            self.error = error.localizedDescription
        }
    }
}

private struct UserRow: View {
    let user: WPUser

    var body: some View {
        HStack(spacing: 12) {
            ZStack {
                Circle().fill(Color.accentColor.opacity(0.15))
                if let url = user.avatarURL {
                    AsyncImage(url: url) { image in
                        image.resizable().scaledToFill()
                    } placeholder: {
                        Text(initials).font(.subheadline.weight(.bold)).foregroundStyle(Color.accentColor)
                    }
                    .clipShape(Circle())
                } else {
                    Text(initials).font(.subheadline.weight(.bold)).foregroundStyle(Color.accentColor)
                }
            }
            .frame(width: 40, height: 40)

            VStack(alignment: .leading, spacing: 2) {
                Text(user.displayName).font(.subheadline.weight(.semibold))
                if let e = user.email, !e.isEmpty {
                    Text(e).font(.caption).foregroundStyle(.secondary).lineLimit(1)
                } else if let u = user.username, !u.isEmpty {
                    Text("@\(u)").font(.caption).foregroundStyle(.secondary)
                }
                if !user.roleLabel.isEmpty {
                    StatusPill(text: user.roleLabel, color: .accentColor)
                }
            }
        }
        .padding(.vertical, 2)
    }

    private var initials: String {
        let parts = user.displayName.split(separator: " ").prefix(2)
        return parts.compactMap { $0.first }.map { String($0) }.joined().uppercased()
    }
}
