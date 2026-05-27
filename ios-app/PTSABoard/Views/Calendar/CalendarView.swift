import SwiftUI

/// Calendar tab — lists upcoming PTSA events read directly from Microsoft
/// Graph shared calendars using the signed-in user's delegated permissions.
struct CalendarView: View {
    @EnvironmentObject var auth: AuthService

    // Persisted user prefs (one comma-joined string per device).
    @AppStorage("calendar.selectedIds") private var selectedIdsCSV: String = ""

    @State private var selectedDate = Date()
    @State private var calendars: [SharedGraphCalendar] = AppConfig.sharedGraphCalendars
    @State private var events: [SharedGraphCalendarEvent] = []
    @State private var selection: Set<String> = []
    @State private var loading = false
    @State private var error: String?
    @State private var showCreate = false
    @State private var showPicker = false

    private var calendarsById: [String: SharedGraphCalendar] {
        Dictionary(uniqueKeysWithValues: calendars.map { ($0.id, $0) })
    }

    private var eventsToday: [SharedGraphCalendarEvent] {
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
            loadCalendarSources()
            await loadEvents()
        }
        .task {
            loadCalendarSources()
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
            CalendarPickerSheet(calendars: calendars, selection: $selection)
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
                let names = selection.compactMap { calendarsById[$0]?.name }.sorted()
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
    private func loadCalendarSources() {
        calendars = AppConfig.sharedGraphCalendars
        guard selection.isEmpty else { return }

        let saved = selectedIdsCSV
            .split(separator: ",")
            .map { String($0) }
            .filter { !$0.isEmpty }

        if !saved.isEmpty {
            let valid = Set(calendars.map { $0.id })
            selection = Set(saved).intersection(valid)
        } else {
            selection = Set(calendars.map { $0.id })
            selectedIdsCSV = selection.sorted().joined(separator: ",")
        }
    }

    private var selectedCalendars: [SharedGraphCalendar] {
        if selection.isEmpty {
            return calendars
        }
        return calendars.filter { selection.contains($0.id) }
    }

    @MainActor
    private func loadEvents() async {
        loading = true; defer { loading = false }
        do {
            let cal = Calendar.current
            let start = cal.date(byAdding: .day, value: -7, to: cal.startOfDay(for: selectedDate)) ?? selectedDate
            let end   = cal.date(byAdding: .day, value: 60, to: cal.startOfDay(for: selectedDate)) ?? selectedDate
            let token = try await auth.graphAccessToken()
            events = try await GraphService.shared.sharedCalendarEvents(
                accessToken: token,
                calendars: selectedCalendars,
                from: start,
                to: end
            )
            error = nil
        } catch {
            if let api = error as? APIError,
               case .http(let code, _) = api,
               code == 403 || code == 404 {
                self.error = "Calendar access is not available yet. Make sure your Microsoft account has been granted access to the selected shared calendar."
            } else {
                self.error = error.localizedDescription
            }
        }
    }
}

private struct EventRow: View {
    let event: SharedGraphCalendarEvent

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
