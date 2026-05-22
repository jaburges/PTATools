import SwiftUI

struct EventEditView: View {
    @EnvironmentObject var auth: AuthService
    @Environment(\.dismiss) private var dismiss

    @State var initialDate: Date
    var onCreated: (GraphEvent) -> Void

    @State private var subject = ""
    @State private var location = ""
    @State private var notes = ""
    @State private var start = Date()
    @State private var end = Date().addingTimeInterval(3600)
    @State private var allDay = false
    @State private var saving = false
    @State private var error: String?

    init(initialDate: Date, onCreated: @escaping (GraphEvent) -> Void) {
        self.initialDate = initialDate
        self.onCreated = onCreated
        let cal = Calendar.current
        let s = cal.date(bySettingHour: 9, minute: 0, second: 0, of: initialDate) ?? initialDate
        let e = cal.date(byAdding: .hour, value: 1, to: s) ?? initialDate
        _start = State(initialValue: s)
        _end = State(initialValue: e)
    }

    var body: some View {
        Form {
            Section("Event") {
                TextField("Title", text: $subject)
                TextField("Location", text: $location)
                Toggle("All day", isOn: $allDay)
                DatePicker("Starts", selection: $start, displayedComponents: allDay ? [.date] : [.date, .hourAndMinute])
                DatePicker("Ends", selection: $end, displayedComponents: allDay ? [.date] : [.date, .hourAndMinute])
            }
            Section("Notes") {
                TextField("Optional", text: $notes, axis: .vertical).lineLimit(3...10)
            }
        }
        .navigationTitle("New Event")
        .navigationBarTitleDisplayMode(.inline)
        .toolbar {
            ToolbarItem(placement: .cancellationAction) { Button("Cancel") { dismiss() } }
            ToolbarItem(placement: .confirmationAction) {
                Button("Add") { Task { await save() } }.disabled(subject.isEmpty || saving)
            }
        }
        .overlay { if saving { ProgressView().controlSize(.large) } }
        .overlay(alignment: .top) {
            if let error {
                ErrorBanner(message: error) { self.error = nil }.padding(.top, 4)
            }
        }
    }

    @MainActor
    private func save() async {
        saving = true; defer { saving = false }
        do {
            let html = """
            <html><body>\(notes.replacingOccurrences(of: "\n", with: "<br>"))</body></html>
            """
            let token = try await auth.graphAccessToken()
            let created = try await GraphService.shared.createSharedEvent(
                accessToken: token,
                subject: subject,
                bodyHTML: html,
                start: start,
                end: end,
                isAllDay: allDay,
                location: location.isEmpty ? nil : location
            )
            onCreated(created)
            dismiss()
        } catch {
            self.error = error.localizedDescription
        }
    }
}
