import SwiftUI

/// Calendar tab — lists upcoming PTSA events sourced from `pta_event`
/// posts via `/wp-json/ptsa/v1/events`, with a multi-select picker driven
/// by the Calendars module mappings (`/ptsa/v1/calendars`).
struct CalendarView: View {
    @EnvironmentObject var auth: AuthService

    // Persisted user prefs (one comma-joined string per device).
    @AppStorage("calendar.selectedIds") private var selectedIdsCSV: String = ""

    @State private var selectedDate = Date()
    @State private var mappings: [CalendarMapping] = []
    @State private var events: [PtaEvent] = []
    @State private var selection: Set<String> = []
    @State private var loading = false
    @State private var error: String?
    @State private var showCreate = false
    @State private var showPicker = false

    private var mappingsById: [String: CalendarMapping] {
        Dictionary(uniqueKeysWithValues: mappings.map { ($0.calendarId, $0) })
    }

    private var eventsToday: [PtaEvent] {
        let cal = Calendar.current
        return events
            .filter { ev in
                guard let s = ev.start else { return false }
                return cal.isDate(s, inSameDayAs: selectedDate)
            }
            .sorted { ($0.start ?? .distantPast) < ($1.start ?? .distantPast) }
    }

    var body: some View {
        VStack(spacing: 0) {
            DatePicker(
                "Date",
                selection: $selectedDate,
                displayedComponents: .date
            )
            .datePickerStyle(.graphical)
            .padding(.horizontal)
            .padding(.bottom, 4)
            .onChange(of: selectedDate) { _, _ in Task { await loadEvents() } }

            Divider()

            selectionStrip
                .padding(.horizontal)
                .padding(.vertical, 8)

            Divider()

            Group {
                if eventsToday.isEmpty && !loading {
                    EmptyStateView(
                        systemImage: "calendar",
                        title: "No events",
                        message: error ?? "Nothing scheduled for this day on the selected calendars."
                    )
                } else {
                    List {
                        ForEach(eventsToday) { ev in
                            EventRow(event: ev)
                        }
                    }
                    .listStyle(.plain)
                }
            }
        }
        .navigationTitle("Calendar")
        .toolbar {
            ToolbarItem(placement: .topBarLeading) {
                Button { selectedDate = Date() } label: { Text("Today") }
            }
            ToolbarItem(placement: .topBarTrailing) {
                Button { showPicker = true } label: {
                    Image(systemName: "line.3.horizontal.decrease.circle")
                }
            }
            ToolbarItem(placement: .topBarTrailing) {
                Button { showCreate = true } label: { Image(systemName: "plus.circle.fill") }
            }
        }
        .refreshable {
            await loadMappings()
            await loadEvents()
        }
        .task {
            await loadMappings()
            await loadEvents()
        }
        .sheet(isPresented: $showCreate) {
            NavigationStack {
                EventEditView(initialDate: selectedDate) { _ in
                    Task { await loadEvents() }
                }
            }
        }
        .sheet(isPresented: $showPicker) {
            CalendarPickerSheet(mappings: mappings, selection: $selection)
                .onDisappear {
                    selectedIdsCSV = selection.sorted().joined(separator: ",")
                    Task { await loadEvents() }
                }
        }
        .overlay(alignment: .top) {
            if let error {
                ErrorBanner(message: error) { self.error = nil }.padding(.top, 4)
            }
        }
        .overlay {
            if loading && events.isEmpty { ProgressView().controlSize(.large) }
        }
    }

    private var selectionStrip: some View {
        HStack(spacing: 8) {
            Image(systemName: "calendar.badge.checkmark")
                .foregroundStyle(.secondary)
            if selection.isEmpty {
                Text("All calendars")
                    .font(.subheadline.weight(.medium))
            } else {
                let names = selection.compactMap { mappingsById[$0]?.name }.sorted()
                Text(names.prefix(2).joined(separator: ", ")
                     + (names.count > 2 ? " +\(names.count - 2)" : ""))
                    .font(.subheadline.weight(.medium))
                    .lineLimit(1)
            }
            Spacer()
            Button { showPicker = true } label: {
                Text(selection.isEmpty ? "Pick" : "Change")
                    .font(.caption.weight(.semibold))
            }
            .buttonStyle(.bordered)
            .controlSize(.small)
        }
    }

    // MARK: - Data loading

    @MainActor
    private func loadMappings() async {
        do {
            let list = try await CalendarsService.shared.mappings()
            self.mappings = list

            // Restore persisted selection (drop any IDs no longer present).
            if selection.isEmpty {
                let saved = selectedIdsCSV
                    .split(separator: ",")
                    .map { String($0) }
                    .filter { !$0.isEmpty }
                if !saved.isEmpty {
                    let valid = Set(list.map { $0.calendarId })
                    selection = Set(saved).intersection(valid)
                } else {
                    // Default to all sync-enabled mappings on first run.
                    selection = Set(list.filter { $0.syncEnabled }.map { $0.calendarId })
                    selectedIdsCSV = selection.sorted().joined(separator: ",")
                }
            }
        } catch {
            self.error = "Could not load calendars: \(error.localizedDescription)"
        }
    }

    @MainActor
    private func loadEvents() async {
        loading = true; defer { loading = false }
        do {
            let cal = Calendar.current
            let start = cal.date(byAdding: .day, value: -7, to: cal.startOfDay(for: selectedDate)) ?? selectedDate
            let end   = cal.date(byAdding: .day, value: 60, to: cal.startOfDay(for: selectedDate)) ?? selectedDate
            let ids = Array(selection)
            events = try await CalendarsService.shared.events(from: start, to: end, calendarIds: ids)
            error = nil
        } catch {
            self.error = error.localizedDescription
        }
    }
}

private struct EventRow: View {
    let event: PtaEvent

    var body: some View {
        VStack(alignment: .leading, spacing: 6) {
            HStack {
                Circle()
                    .fill(EventRow.color(for: event.calendarId))
                    .frame(width: 8, height: 8)
                Text(event.subject)
                    .font(.headline)
                Spacer()
                if let s = event.start {
                    Text(event.allDay ? "All-day" : s.formatted(date: .omitted, time: .shortened))
                        .font(.subheadline)
                        .monospacedDigit()
                        .foregroundStyle(.secondary)
                }
            }
            HStack(spacing: 6) {
                if !event.calendarName.isEmpty {
                    Text(event.calendarName)
                        .font(.caption2.weight(.semibold))
                        .padding(.horizontal, 6).padding(.vertical, 2)
                        .background(EventRow.color(for: event.calendarId).opacity(0.15))
                        .foregroundStyle(EventRow.color(for: event.calendarId))
                        .clipShape(Capsule())
                }
                if let loc = event.location, !loc.isEmpty {
                    Label(loc, systemImage: "mappin.and.ellipse")
                        .font(.caption)
                        .foregroundStyle(.secondary)
                }
            }
            if let preview = event.bodyPreview?.trimmingCharacters(in: .whitespacesAndNewlines), !preview.isEmpty {
                Text(preview).font(.caption).foregroundStyle(.secondary).lineLimit(2)
            }
        }
        .padding(.vertical, 4)
    }

    /// Deterministic color per calendar id so each calendar gets its own
    /// pill / dot color without server-side configuration.
    static func color(for calendarId: String) -> Color {
        let palette: [Color] = [.blue, .green, .purple, .orange, .pink, .teal, .indigo, .red, .mint]
        var hash = 5381
        for byte in calendarId.utf8 { hash = ((hash << 5) &+ hash) &+ Int(byte) }
        return palette[abs(hash) % palette.count]
    }
}
