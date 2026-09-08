import SwiftUI

struct VolunteersView: View {
    @State private var sheets: [VolunteerSheetSummary] = []
    @State private var loading = false
    @State private var error: String?

    var body: some View {
        Group {
            if sheets.isEmpty && !loading {
                EmptyStateView(
                    systemImage: "hands.sparkles",
                    title: "No volunteer sheets",
                    message: error ?? "Pull to refresh after a sign-up sheet is published."
                )
            } else {
                List {
                    ForEach(sheets) { sheet in
                        NavigationLink(value: sheet) {
                            VolunteerSheetRow(sheet: sheet)
                        }
                    }
                }
                .listStyle(.insetGrouped)
            }
        }
        .navigationTitle("Volunteer sign-up")
        .navigationDestination(for: VolunteerSheetSummary.self) { sheet in
            VolunteerSheetDetailView(sheetId: sheet.id)
        }
        .refreshable { await load() }
        .task { await load() }
        .overlay(alignment: .top) {
            if let error, !sheets.isEmpty {
                ErrorBanner(message: error) { self.error = nil }.padding(.top, 4)
            }
        }
        .overlay {
            if loading && sheets.isEmpty { ProgressView().controlSize(.large) }
        }
    }

    @MainActor
    private func load() async {
        loading = true
        defer { loading = false }
        do {
            sheets = try await WordPressService.shared.volunteerSheets()
            error = nil
        } catch {
            self.error = error.localizedDescription
        }
    }
}

struct VolunteerSheetRow: View {
    let sheet: VolunteerSheetSummary

    var body: some View {
        VStack(alignment: .leading, spacing: 6) {
            HStack {
                Text(sheet.title.isEmpty ? "Untitled sheet" : sheet.title)
                    .font(.headline)
                Spacer()
                StatusPill(text: sheet.status.capitalized, color: sheet.status == "open" ? .green : .secondary)
            }
            if !sheet.eventLabel.isEmpty {
                Text(sheet.eventLabel)
                    .font(.subheadline)
                    .foregroundStyle(.secondary)
            }
            HStack {
                Text(sheet.fillLabel)
                if sheet.spots_open > 0 {
                    Text("· \(sheet.spots_open) open")
                        .foregroundStyle(.orange)
                }
            }
            .font(.caption.weight(.semibold))
        }
        .padding(.vertical, 4)
    }
}
