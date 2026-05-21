import SwiftUI

struct TodoDetailView: View {
    @EnvironmentObject var auth: AuthService
    @Environment(\.dismiss) private var dismiss

    @State var item: TodoItem?
    var onChange: (TodoItem) -> Void
    var onDelete: (Int) -> Void

    @State private var title: String = ""
    @State private var details: String = ""
    @State private var priority: TodoPriority = .normal
    @State private var hasDue: Bool = false
    @State private var due: Date = .now
    @State private var saving = false
    @State private var error: String?

    private var isNew: Bool { item == nil }

    private var isCreator: Bool {
        guard let me = auth.profile, let item else { return false }
        return me.email.lowercased() == item.createdByEmail.lowercased()
    }

    private var canEdit: Bool {
        isNew || isCreator || (auth.profile?.isTodoAdmin ?? false)
    }

    private var canToggleComplete: Bool {
        auth.profile?.isTodoAdmin ?? false
    }

    var body: some View {
        Form {
            Section {
                if canEdit {
                    TextField("Title", text: $title)
                    TextField("Details", text: $details, axis: .vertical).lineLimit(3...10)
                } else {
                    if let item {
                        VStack(alignment: .leading, spacing: 8) {
                            Text(item.title).font(.headline)
                            if let d = item.details, !d.isEmpty { Text(d) }
                        }
                    }
                }
            } header: { Text("Item") }

            Section {
                if canEdit {
                    Picker("Priority", selection: $priority) {
                        ForEach(TodoPriority.allCases) { Text($0.label).tag($0) }
                    }
                    Toggle("Has due date", isOn: $hasDue)
                    if hasDue {
                        DatePicker("Due", selection: $due, displayedComponents: .date)
                    }
                } else if let item {
                    HStack { Text("Priority"); Spacer(); Text(item.priority.label).foregroundStyle(.secondary) }
                    if let d = item.dueDate {
                        HStack { Text("Due"); Spacer(); Text(d, style: .date).foregroundStyle(.secondary) }
                    }
                }
            } header: { Text("Schedule") }

            if let item, !isNew {
                Section("Meta") {
                    HStack { Text("Created by"); Spacer(); Text(item.createdByName ?? item.createdByEmail).foregroundStyle(.secondary) }
                    HStack { Text("Created at"); Spacer(); Text(item.createdAt, style: .date).foregroundStyle(.secondary) }
                    if item.completed, let by = item.completedByEmail, let at = item.completedAt {
                        HStack { Text("Completed by"); Spacer(); Text(by).foregroundStyle(.secondary) }
                        HStack { Text("Completed at"); Spacer(); Text(at, style: .date).foregroundStyle(.secondary) }
                    }
                }

                Section("Status") {
                    if canToggleComplete {
                        Button {
                            Task { await toggleComplete() }
                        } label: {
                            Label(
                                item.completed ? "Reopen" : "Mark complete",
                                systemImage: item.completed ? "arrow.uturn.backward.circle.fill" : "checkmark.circle.fill"
                            )
                        }
                    } else {
                        Label(
                            item.completed ? "Completed" : "Open",
                            systemImage: item.completed ? "checkmark.circle.fill" : "circle"
                        )
                        .foregroundStyle(item.completed ? .green : .secondary)
                    }
                }

                if isCreator || auth.profile?.isTodoAdmin == true {
                    Section {
                        Button(role: .destructive) {
                            Task { await deleteItem() }
                        } label: { Label("Delete item", systemImage: "trash") }
                    }
                }
            }
        }
        .navigationTitle(isNew ? "New backlog item" : (item?.title ?? "Item"))
        .navigationBarTitleDisplayMode(.inline)
        .toolbar {
            if isNew {
                ToolbarItem(placement: .cancellationAction) { Button("Cancel") { dismiss() } }
                ToolbarItem(placement: .confirmationAction) { Button("Add") { Task { await create() } }.disabled(title.isEmpty || saving) }
            } else if canEdit {
                ToolbarItem(placement: .confirmationAction) { Button("Save") { Task { await update() } }.disabled(saving) }
            }
        }
        .overlay { if saving { ProgressView().controlSize(.large) } }
        .overlay(alignment: .top) {
            if let error {
                ErrorBanner(message: error) { self.error = nil }.padding(.top, 4)
            }
        }
        .onAppear { hydrate() }
    }

    private func hydrate() {
        guard let item else { return }
        title = item.title
        details = item.details ?? ""
        priority = item.priority
        if let d = item.dueDate { due = d; hasDue = true }
    }

    @MainActor
    private func create() async {
        guard let me = auth.profile else { return }
        saving = true; defer { saving = false }

        var draft = TodoItem(
            title: title,
            details: details.isEmpty ? nil : details,
            dueDate: hasDue ? due : nil,
            createdByEmail: me.email,
            createdByName: me.displayName,
            priority: priority
        )

        do {
            draft = try await WordPressService.shared.createTodo(draft)
        } catch {
            // If the backend isn't available, still add it locally for the session.
            self.error = "Saved locally only (server: \(error.localizedDescription))."
        }
        onChange(draft)
        dismiss()
    }

    @MainActor
    private func update() async {
        guard var working = item else { return }
        saving = true; defer { saving = false }
        working.title = title
        working.details = details.isEmpty ? nil : details
        working.dueDate = hasDue ? due : nil
        working.priority = priority
        do {
            working = try await WordPressService.shared.updateTodo(working)
        } catch {
            self.error = error.localizedDescription
        }
        item = working
        onChange(working)
    }

    @MainActor
    private func toggleComplete() async {
        guard var working = item, auth.profile?.isTodoAdmin == true else { return }
        saving = true; defer { saving = false }
        working.completed.toggle()
        working.completedAt = working.completed ? Date() : nil
        working.completedByEmail = working.completed ? auth.profile?.email : nil
        do {
            working = try await WordPressService.shared.updateTodo(working)
        } catch {
            self.error = error.localizedDescription
        }
        item = working
        onChange(working)
    }

    @MainActor
    private func deleteItem() async {
        guard let item else { return }
        saving = true; defer { saving = false }
        do {
            try await WordPressService.shared.deleteTodo(item.id)
        } catch {
            self.error = error.localizedDescription
        }
        onDelete(item.id)
        dismiss()
    }
}
