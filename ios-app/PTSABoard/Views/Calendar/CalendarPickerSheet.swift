import SwiftUI

/// Multi-select sheet listing every Outlook calendar known to the PTA
/// Tools Calendars module. Lets the user check/uncheck which ones the
/// Calendar view should pull events from.
///
/// The set of selected calendar IDs is persisted across launches via
/// `@AppStorage` (a comma-joined string), so each device remembers its
/// own preferences.
struct CalendarPickerSheet: View {
    let mappings: [CalendarMapping]
    @Binding var selection: Set<String>

    @Environment(\.dismiss) private var dismiss
    @State private var showDisabled = false

    private var visibleMappings: [CalendarMapping] {
        showDisabled ? mappings : mappings.filter { $0.syncEnabled }
    }

    var body: some View {
        NavigationStack {
            List {
                Section {
                    Button {
                        selection = Set(visibleMappings.map { $0.calendarId })
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
                    ForEach(visibleMappings) { m in
                        Button {
                            if selection.contains(m.calendarId) {
                                selection.remove(m.calendarId)
                            } else {
                                selection.insert(m.calendarId)
                            }
                        } label: {
                            HStack(spacing: 12) {
                                Image(systemName: selection.contains(m.calendarId)
                                      ? "checkmark.square.fill" : "square")
                                    .foregroundStyle(selection.contains(m.calendarId)
                                                     ? Color.accentColor : Color.secondary)
                                    .font(.title3)
                                VStack(alignment: .leading, spacing: 2) {
                                    HStack(spacing: 6) {
                                        Text(m.name).font(.body).foregroundStyle(.primary)
                                        if !m.syncEnabled {
                                            Text("paused")
                                                .font(.caption2.weight(.semibold))
                                                .padding(.horizontal, 6).padding(.vertical, 2)
                                                .background(Color.orange.opacity(0.15))
                                                .foregroundStyle(.orange)
                                                .clipShape(Capsule())
                                        }
                                    }
                                    Text(m.calendarId)
                                        .font(.caption2)
                                        .foregroundStyle(.tertiary)
                                        .lineLimit(1)
                                        .truncationMode(.middle)
                                }
                                Spacer(minLength: 8)
                                Text("\(m.eventCount)")
                                    .font(.caption.monospacedDigit())
                                    .foregroundStyle(.secondary)
                            }
                        }
                        .buttonStyle(.plain)
                    }
                } header: {
                    Text("Calendars (\(visibleMappings.count))")
                } footer: {
                    if !showDisabled {
                        Text("Sync-paused calendars are hidden. Tap “Show paused” to see them.")
                            .font(.caption)
                    }
                }

                Section {
                    Toggle("Show paused calendars", isOn: $showDisabled)
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
