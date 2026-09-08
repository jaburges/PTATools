import SwiftUI

struct VolunteerSheetDetailView: View {
    let sheetId: Int

    @State private var sheet: VolunteerSheetDetail?
    @State private var loading = false
    @State private var error: String?

    var body: some View {
        Group {
            if let sheet {
                List {
                    Section {
                        if !sheet.eventLabel.isEmpty {
                            LabeledContent("When", value: sheet.eventLabel)
                        }
                        if let loc = sheet.event_location, !loc.isEmpty {
                            LabeledContent("Where", value: loc)
                        }
                        LabeledContent("Fill", value: sheet.fillLabel)
                        if let desc = sheet.description, !desc.isEmpty {
                            Text(desc)
                        }
                    }
                    ForEach(sheet.activities) { activity in
                        Section {
                            if let time = activity.time_label, !time.isEmpty {
                                Text(time).foregroundStyle(.secondary)
                            }
                            if let desc = activity.description, !desc.isEmpty {
                                Text(desc).font(.subheadline)
                            }
                            if activity.signups.isEmpty {
                                Text("No one signed up yet")
                                    .foregroundStyle(.secondary)
                            } else {
                                ForEach(activity.signups) { person in
                                    VStack(alignment: .leading, spacing: 2) {
                                        Text(person.display_name)
                                        if let email = person.email, !email.isEmpty {
                                            Text(email)
                                                .font(.caption)
                                                .foregroundStyle(.secondary)
                                        }
                                    }
                                }
                            }
                        } header: {
                            HStack {
                                Text(activity.name)
                                Spacer()
                                Text(activity.fillLabel)
                                    .textCase(nil)
                                    .foregroundStyle(activity.spots_open > 0 ? .orange : .secondary)
                            }
                        }
                    }
                }
                .listStyle(.insetGrouped)
            } else if loading {
                ProgressView().controlSize(.large)
            } else {
                EmptyStateView(
                    systemImage: "hands.sparkles",
                    title: "Sheet unavailable",
                    message: error ?? "This sign-up sheet could not be loaded."
                )
            }
        }
        .navigationTitle(sheet?.title ?? "Sheet")
        .navigationBarTitleDisplayMode(.inline)
        .refreshable { await load() }
        .task { await load() }
        .overlay(alignment: .top) {
            if let error, sheet != nil {
                ErrorBanner(message: error) { self.error = nil }.padding(.top, 4)
            }
        }
    }

    @MainActor
    private func load() async {
        loading = true
        defer { loading = false }
        do {
            sheet = try await WordPressService.shared.volunteerSheet(sheetId)
            error = nil
        } catch {
            self.error = error.localizedDescription
        }
    }
}
