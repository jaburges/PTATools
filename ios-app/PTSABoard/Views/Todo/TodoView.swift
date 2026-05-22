import SwiftUI

struct TodoView: View {
    @EnvironmentObject var auth: AuthService

    @State private var items: [TodoItem] = []
    @State private var loading = false
    @State private var error: String?
    @State private var showCreate = false
    @State private var showCompleted = false

    var body: some View {
        Group {
            if visibleItems.isEmpty && !loading {
                EmptyStateView(
                    systemImage: "checklist",
                    title: "Nothing in the backlog",
                    message: error ?? "Tap + to add a new tech-backlog item."
                )
            } else {
                List {
                    if !openItems.isEmpty {
                        Section("Open") {
                            ForEach(openItems) { item in
                                NavigationLink(value: item) { TodoRow(item: item) }
                            }
                            .onDelete(perform: deleteItems(_:))
                        }
                    }
                    if showCompleted, !completedItems.isEmpty {
                        Section("Completed") {
                            ForEach(completedItems) { item in
                                NavigationLink(value: item) { TodoRow(item: item) }
                            }
                            .onDelete(perform: deleteItems(_:))
                        }
                    }
                }
                .listStyle(.insetGrouped)
            }
        }
        .navigationTitle("Tech Backlog")
        .navigationDestination(for: TodoItem.self) { item in
            TodoDetailView(item: item, onChange: handleChange, onDelete: deleteItem)
                .environmentObject(auth)
        }
        .toolbar {
            ToolbarItem(placement: .topBarLeading) {
                Menu {
                    Toggle("Show completed", isOn: $showCompleted)
                } label: { Image(systemName: "ellipsis.circle") }
            }
            ToolbarItem(placement: .topBarTrailing) {
                Button { showCreate = true } label: { Image(systemName: "plus.circle.fill") }
            }
        }
        .sheet(isPresented: $showCreate) {
            NavigationStack {
                TodoDetailView(item: nil, onChange: handleChange, onDelete: deleteItem)
                    .environmentObject(auth)
            }
        }
        .refreshable { await load() }
        .task { await load() }
        .overlay(alignment: .top) {
            if let error {
                ErrorBanner(message: error) { self.error = nil }.padding(.top, 4)
            }
        }
        .overlay {
            if loading && items.isEmpty { ProgressView().controlSize(.large) }
        }
    }

    private var openItems: [TodoItem] {
        items.filter { !$0.completed }
            .sorted {
                if $0.priority != $1.priority { return prio($0) > prio($1) }
                return ($0.dueDate ?? .distantFuture) < ($1.dueDate ?? .distantFuture)
            }
    }

    private var completedItems: [TodoItem] {
        items.filter { $0.completed }
            .sorted { ($0.completedAt ?? .distantPast) > ($1.completedAt ?? .distantPast) }
    }

    private var visibleItems: [TodoItem] {
        showCompleted ? items : openItems
    }

    private func prio(_ t: TodoItem) -> Int {
        switch t.priority { case .high: return 2; case .normal: return 1; case .low: return 0 }
    }

    private func handleChange(_ item: TodoItem) {
        if let idx = items.firstIndex(where: { $0.id == item.id }) { items[idx] = item }
        else { items.append(item) }
    }

    private func deleteItem(_ id: Int) {
        items.removeAll { $0.id == id }
    }

    private func deleteItems(_ offsets: IndexSet) {
        // Only admin can delete arbitrary items
        guard auth.profile?.isTodoAdmin == true else { return }
        let arr = visibleItems
        let ids = offsets.map { arr[$0].id }
        items.removeAll { ids.contains($0.id) }
        for id in ids {
            Task { try? await WordPressService.shared.deleteTodo(id) }
        }
    }

    @MainActor
    private func load() async {
        loading = true; defer { loading = false }
        do {
            items = try await WordPressService.shared.listTodos()
            error = nil
        } catch APIError.http(let code, _) where code == 404 {
            // Endpoint not deployed yet — fall back to local cache so the UI is still useful.
            error = "Backlog endpoint missing on server (404). Showing local-only items."
        } catch {
            self.error = error.localizedDescription
        }
    }
}

private struct TodoRow: View {
    let item: TodoItem

    var body: some View {
        HStack(alignment: .top, spacing: 12) {
            Image(systemName: item.completed ? "checkmark.circle.fill" : "circle")
                .font(.title3)
                .foregroundStyle(item.completed ? .green : .secondary)
                .frame(width: 24)
            VStack(alignment: .leading, spacing: 4) {
                Text(item.title)
                    .font(.subheadline.weight(.semibold))
                    .strikethrough(item.completed)
                if let due = item.dueDate {
                    Label(due.formatted(date: .abbreviated, time: .omitted),
                          systemImage: "calendar.badge.clock")
                        .font(.caption)
                        .foregroundStyle(overdue(due) ? .red : .secondary)
                }
                HStack {
                    StatusPill(text: item.priority.label, color: priorityColor(item.priority))
                    Text(item.createdByName ?? item.createdByEmail)
                        .font(.caption)
                        .foregroundStyle(.secondary)
                        .lineLimit(1)
                }
            }
        }
        .padding(.vertical, 4)
    }

    private func priorityColor(_ p: TodoPriority) -> Color {
        switch p { case .low: return .gray; case .normal: return .blue; case .high: return .red }
    }

    private func overdue(_ d: Date) -> Bool {
        guard !item.completed else { return false }
        return d < Calendar.current.startOfDay(for: Date())
    }
}
