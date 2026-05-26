import SwiftUI

/// Multi-select sheet listing every shared Microsoft 365 calendar configured
/// for the app. The Calendar view reads selected calendars directly from
/// Microsoft Graph using the signed-in user's delegated permissions.
///
/// The set of selected calendar IDs is persisted across launches via
/// `@AppStorage` (a comma-joined string), so each device remembers its
/// own preferences.
struct CalendarPickerSheet: View {
    let calendars: [SharedGraphCalendar]
    @Binding var selection: Set<String>

    @Environment(\.dismiss) private var dismiss

    var body: some View {
        NavigationStack {
            List {
                Section {
                    Button {
                        selection = Set(calendars.map { $0.id })
                    } label: {
                        Label("Select all", systemImage: "checkmark.circle.fill")
                    }
                    Button(role: .destructive) {
                        selection.removeAll()
                    } label: {
                        Label("Clear selection", systemImage: "minus.circle.fill")
                    }
                }

                Section {
                    ForEach(calendars) { calendar in
                        Button {
                            if selection.contains(calendar.id) {
                                selection.remove(calendar.id)
                            } else {
                                selection.insert(calendar.id)
                            }
                        } label: {
                            HStack(spacing: 12) {
                                Image(systemName: selection.contains(calendar.id)
                                      ? "checkmark.square.fill" : "square")
                                    .foregroundStyle(selection.contains(calendar.id)
                                                     ? Color.accentColor : Color.secondary)
                                    .font(.title3)
                                VStack(alignment: .leading, spacing: 2) {
                                    Text(calendar.name).font(.body).foregroundStyle(.primary)
                                    Text(calendar.mailbox)
                                        .font(.caption2)
                                        .foregroundStyle(.tertiary)
                                        .lineLimit(1)
                                        .truncationMode(.middle)
                                }
                            }
                        }
                        .buttonStyle(.plain)
                    }
                } header: {
                    Text("Calendars (\(calendars.count))")
                } footer: {
                    Text("Only calendars that have been shared with your Microsoft account will load.")
                        .font(.caption)
                }
            }
            .navigationTitle("Pick calendars")
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                ToolbarItem(placement: .topBarTrailing) {
                    Button("Done") { dismiss() }.fontWeight(.semibold)
                }
            }
        }
    }
}
