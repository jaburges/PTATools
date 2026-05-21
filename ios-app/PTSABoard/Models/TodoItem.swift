import Foundation

struct TodoItem: Codable, Identifiable, Hashable {
    let id: Int
    var title: String
    var details: String?
    var dueDate: Date?
    var createdAt: Date
    var createdByEmail: String
    var createdByName: String?
    var completed: Bool
    var completedAt: Date?
    var completedByEmail: String?
    var priority: TodoPriority

    init(
        id: Int = Int(Date().timeIntervalSince1970 * 1000),
        title: String,
        details: String? = nil,
        dueDate: Date? = nil,
        createdAt: Date = Date(),
        createdByEmail: String,
        createdByName: String? = nil,
        completed: Bool = false,
        completedAt: Date? = nil,
        completedByEmail: String? = nil,
        priority: TodoPriority = .normal
    ) {
        self.id = id
        self.title = title
        self.details = details
        self.dueDate = dueDate
        self.createdAt = createdAt
        self.createdByEmail = createdByEmail
        self.createdByName = createdByName
        self.completed = completed
        self.completedAt = completedAt
        self.completedByEmail = completedByEmail
        self.priority = priority
    }
}

enum TodoPriority: String, Codable, CaseIterable, Identifiable {
    case low, normal, high
    var id: String { rawValue }

    var label: String { rawValue.capitalized }

    var color: String {
        switch self {
        case .low:    return "#8E8E93"
        case .normal: return "#007AFF"
        case .high:   return "#FF3B30"
        }
    }
}
