import SwiftUI

struct CalendarView: View {
    @EnvironmentObject var auth: AuthService
    @State private var selectedDate = Date()
    @State private var events: [GraphEvent] = []
    @State private var loading = false
    @State private var error: String?
    @State private var showCreate = false

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
            .onChange(of: selectedDate) { _, _ in Task { await load() } }

            Divider()

            Group {
                if eventsToday.isEmpty && !loading {
                    EmptyStateView(
                        systemImage: "calendar",
                        title: "No events",
                        message: error ?? "Nothing scheduled for this day."
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
                Button { showCreate = true } label: { Image(systemName: "plus.circle.fill") }
            }
        }
        .refreshable { await load() }
        .task { await load() }
        .sheet(isPresented: $showCreate) {
            NavigationStack {
                EventEditView(initialDate: selectedDate) { newEv in
                    events.append(newEv)
                }
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

    private var eventsToday: [GraphEvent] {
        let cal = Calendar.current
        return events.filter { ev in
            guard let s = ev.startDate else { return false }
            return cal.isDate(s, inSameDayAs: selectedDate)
        }
        .sorted { ($0.startDate ?? .distantPast) < ($1.startDate ?? .distantPast) }
    }

    @MainActor
    private func load() async {
        loading = true; defer { loading = false }
        do {
            let cal = Calendar.current
            let start = cal.startOfDay(for: selectedDate)
            let end = cal.date(byAdding: .day, value: 14, to: start) ?? start
            let token = try await auth.graphAccessToken()
            events = try await GraphService.shared.sharedCalendarEvents(accessToken: token, from: start, to: end)
            error = nil
        } catch {
            self.error = error.localizedDescription
        }
    }
}

private struct EventRow: View {
    let event: GraphEvent

    var body: some View {
        VStack(alignment: .leading, spacing: 4) {
            HStack {
                Text(event.subject ?? "(No title)")
                    .font(.headline)
                Spacer()
                if let s = event.startDate {
                    Text(s.formatted(date: .omitted, time: .shortened))
                        .font(.subheadline)
                        .monospacedDigit()
                        .foregroundStyle(.secondary)
                }
            }
            if let loc = event.location?.displayName, !loc.isEmpty {
                Label(loc, systemImage: "mappin.and.ellipse")
                    .font(.caption)
                    .foregroundStyle(.secondary)
            }
            if let preview = event.bodyPreview?.trimmingCharacters(in: .whitespacesAndNewlines), !preview.isEmpty {
                Text(preview).font(.caption).foregroundStyle(.secondary).lineLimit(2)
            }
        }
        .padding(.vertical, 4)
    }
}
