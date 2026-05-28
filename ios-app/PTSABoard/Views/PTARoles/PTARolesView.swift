import SwiftUI

struct PTARolesView: View {
    @State private var org: PTAOrgResponse?
    @State private var loading = false
    @State private var error: String?
    @State private var assignmentTarget: PTARole?

    var body: some View {
        Group {
            if let org, !org.departments.isEmpty {
                List {
                    ForEach(org.departments) { department in
                        Section {
                            ForEach(department.roles) { role in
                                RoleCard(role: role) {
                                    assignmentTarget = role
                                } remove: { assignment in
                                    Task { await remove(assignment) }
                                }
                            }
                        } header: {
                            VStack(alignment: .leading, spacing: 4) {
                                Text(department.name)
                                if let vp = department.vp_user {
                                    Text("VP: \(vp.displayName)")
                                        .font(.caption)
                                        .foregroundStyle(.secondary)
                                        .textCase(nil)
                                }
                            }
                        }
                    }
                }
                .listStyle(.insetGrouped)
            } else if loading {
                ProgressView().controlSize(.large)
            } else {
                EmptyStateView(
                    systemImage: "person.3.sequence.fill",
                    title: "No PTA roles",
                    message: error ?? "Pull to refresh after the PTA Roles module is configured."
                )
            }
        }
        .navigationTitle("PTA Roles")
        .toolbar {
            ToolbarItem(placement: .topBarTrailing) {
                Button { Task { await load() } } label: {
                    Image(systemName: "arrow.clockwise")
                }
            }
        }
        .refreshable { await load() }
        .task { await load() }
        .sheet(item: $assignmentTarget) { role in
            NavigationStack {
                AssignPTAUserView(role: role) {
                    assignmentTarget = nil
                    await load()
                }
            }
        }
        .overlay(alignment: .top) {
            if let error {
                ErrorBanner(message: error) { self.error = nil }.padding(.top, 4)
            }
        }
    }

    @MainActor
    private func load() async {
        loading = true
        defer { loading = false }
        do {
            org = try await WordPressService.shared.ptaRolesOrg()
            error = nil
        } catch {
            self.error = error.localizedDescription
        }
    }

    @MainActor
    private func remove(_ assignment: PTAAssignment) async {
        do {
            try await WordPressService.shared.removePTAAssignment(assignment.id)
            await load()
        } catch {
            self.error = error.localizedDescription
        }
    }
}

private struct RoleCard: View {
    let role: PTARole
    var assign: () -> Void
    var remove: (PTAAssignment) -> Void

    var body: some View {
        VStack(alignment: .leading, spacing: 10) {
            HStack(alignment: .top) {
                VStack(alignment: .leading, spacing: 3) {
                    Text(role.name)
                        .font(.subheadline.weight(.semibold))
                    if let description = role.description, !description.isEmpty {
                        Text(description)
                            .font(.caption)
                            .foregroundStyle(.secondary)
                    }
                }
                Spacer()
                StatusPill(text: "\(role.vacancy_count) open", color: role.vacancy_count > 0 ? .green : .secondary)
            }

            if role.assignments.isEmpty {
                Text("No assignments")
                    .font(.caption)
                    .foregroundStyle(.secondary)
            } else {
                ForEach(role.assignments) { assignment in
                    HStack {
                        Image(systemName: assignment.is_primary ? "star.circle.fill" : "person.crop.circle")
                            .foregroundStyle(assignment.is_primary ? .yellow : .secondary)
                        VStack(alignment: .leading, spacing: 2) {
                            Text(assignment.user?.displayName ?? "User #\(assignment.user_id)")
                            if let email = assignment.user?.email, !email.isEmpty {
                                Text(email).font(.caption).foregroundStyle(.secondary)
                            }
                        }
                        Spacer()
                        Button(role: .destructive) {
                            remove(assignment)
                        } label: {
                            Image(systemName: "minus.circle")
                        }
                        .buttonStyle(.borderless)
                        .accessibilityLabel("Remove assignment")
                    }
                }
            }

            Button {
                assign()
            } label: {
                Label("Assign user", systemImage: "person.badge.plus")
            }
            .disabled(role.vacancy_count <= 0)
        }
        .padding(.vertical, 4)
    }
}

private struct AssignPTAUserView: View {
    let role: PTARole
    var onAssigned: () async -> Void

    @Environment(\.dismiss) private var dismiss
    @State private var search = ""
    @State private var users: [WPUser] = []
    @State private var selectedUser: WPUser?
    @State private var isPrimary = false
    @State private var loading = false
    @State private var error: String?

    var body: some View {
        List {
            Section("Role") {
                Text(role.name)
                Toggle("Primary role", isOn: $isPrimary)
            }

            Section("Users") {
                ForEach(users) { user in
                    Button {
                        selectedUser = user
                    } label: {
                        HStack {
                            VStack(alignment: .leading, spacing: 2) {
                                Text(user.displayName)
                                if let email = user.email {
                                    Text(email).font(.caption).foregroundStyle(.secondary)
                                }
                            }
                            Spacer()
                            if selectedUser?.id == user.id {
                                Image(systemName: "checkmark").foregroundStyle(Color.accentColor)
                            }
                        }
                    }
                    .buttonStyle(.plain)
                }
            }
        }
        .navigationTitle("Assign User")
        .navigationBarTitleDisplayMode(.inline)
        .searchable(text: $search, prompt: "Search users")
        .task { await loadUsers() }
        .onChange(of: search) {
            Task {
                try? await Task.sleep(nanoseconds: 350_000_000)
                if !Task.isCancelled { await loadUsers() }
            }
        }
        .toolbar {
            ToolbarItem(placement: .cancellationAction) {
                Button("Cancel") { dismiss() }
            }
            ToolbarItem(placement: .confirmationAction) {
                Button("Assign") { Task { await assign() } }
                    .disabled(selectedUser == nil || loading)
            }
        }
        .overlay { if loading { ProgressView().controlSize(.large) } }
        .overlay(alignment: .top) {
            if let error {
                ErrorBanner(message: error) { self.error = nil }.padding(.top, 4)
            }
        }
    }

    @MainActor
    private func loadUsers() async {
        loading = true
        defer { loading = false }
        do {
            users = try await WordPressService.shared.searchUsers(search, perPage: 50)
            error = nil
        } catch {
            self.error = error.localizedDescription
        }
    }

    @MainActor
    private func assign() async {
        guard let selectedUser else { return }
        loading = true
        defer { loading = false }
        do {
            try await WordPressService.shared.assignPTAUser(userId: selectedUser.id, roleId: role.id, isPrimary: isPrimary)
            await onAssigned()
            dismiss()
        } catch {
            self.error = error.localizedDescription
        }
    }
}
