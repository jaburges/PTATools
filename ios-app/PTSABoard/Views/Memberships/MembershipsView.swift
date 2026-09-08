import SwiftUI

struct MembershipsView: View {
    @EnvironmentObject var auth: AuthService
    @State private var summary: MembershipSummary?
    @State private var members: [MembershipMember] = []
    @State private var search = ""
    @State private var loading = false
    @State private var error: String?

    var body: some View {
        Group {
            if !auth.canReadMemberships {
                EmptyStateView(
                    systemImage: "lock.fill",
                    title: "Finance role required",
                    message: "Memberships are limited to treasurer / finance, shop managers, and administrators."
                )
            } else if members.isEmpty && summary == nil && !loading {
                EmptyStateView(
                    systemImage: "person.crop.rectangle.stack",
                    title: "No members yet",
                    message: error ?? "Pull to refresh after membership orders come in."
                )
            } else {
                List {
                    if let summary {
                        Section {
                            VStack(alignment: .leading, spacing: 8) {
                                Text(summary.headline)
                                    .font(.title3.weight(.semibold))
                                HStack(spacing: 12) {
                                    stat("Family", summary.counts.family)
                                    stat("Individual", summary.counts.individual)
                                    stat("Staff", summary.counts.staff)
                                }
                            }
                            .padding(.vertical, 4)
                        }
                    }
                    Section("Paid members") {
                        ForEach(members) { member in
                            VStack(alignment: .leading, spacing: 4) {
                                HStack {
                                    Text(member.name).font(.headline)
                                    Spacer()
                                    StatusPill(
                                        text: member.membershipLabel,
                                        color: member.membership == "family" ? .green : .blue
                                    )
                                }
                                if let email = member.email, !email.isEmpty {
                                    Text(email).font(.caption).foregroundStyle(.secondary)
                                }
                                if !member.childrenLabel.isEmpty {
                                    Text(member.childrenLabel)
                                        .font(.caption)
                                        .foregroundStyle(.secondary)
                                }
                            }
                            .padding(.vertical, 2)
                        }
                    }
                }
                .listStyle(.insetGrouped)
            }
        }
        .navigationTitle("Memberships")
        .searchable(text: $search, prompt: "Search members")
        .onChange(of: search) { _, _ in
            Task { await loadMembers() }
        }
        .refreshable { await load() }
        .task { await load() }
        .overlay(alignment: .top) {
            if let error, summary != nil {
                ErrorBanner(message: error) { self.error = nil }.padding(.top, 4)
            }
        }
        .overlay {
            if loading && summary == nil && auth.canReadMemberships {
                ProgressView().controlSize(.large)
            }
        }
    }

    private func stat(_ label: String, _ value: Int) -> some View {
        VStack(alignment: .leading, spacing: 2) {
            Text("\(value)").font(.headline)
            Text(label).font(.caption).foregroundStyle(.secondary)
        }
    }

    @MainActor
    private func load() async {
        guard auth.canReadMemberships else { return }
        loading = true
        defer { loading = false }
        do {
            async let summaryTask = WordPressService.shared.membershipSummary()
            async let membersTask = WordPressService.shared.membershipMembers(search: search)
            summary = try await summaryTask
            members = try await membersTask
            error = nil
        } catch {
            self.error = error.localizedDescription
        }
    }

    @MainActor
    private func loadMembers() async {
        guard auth.canReadMemberships else { return }
        do {
            members = try await WordPressService.shared.membershipMembers(search: search)
        } catch {
            self.error = error.localizedDescription
        }
    }
}
