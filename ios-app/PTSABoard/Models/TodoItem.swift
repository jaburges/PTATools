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
    var githubIssueNumber: Int?
    var githubIssueURL: URL?
    var githubIssueState: String?
    var githubIssueError: String?

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
        priority: TodoPriority = .normal,
        githubIssueNumber: Int? = nil,
        githubIssueURL: URL? = nil,
        githubIssueState: String? = nil,
        githubIssueError: String? = nil
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
        self.githubIssueNumber = githubIssueNumber
        self.githubIssueURL = githubIssueURL
        self.githubIssueState = githubIssueState
        self.githubIssueError = githubIssueError
    }

    enum CodingKeys: String, CodingKey {
        case id, title, details, notes, priority, completed
        case dueDate = "due_date"
        case createdAt = "created_at"
        case createdByEmail = "created_by_email"
        case createdBy = "created_by"
        case createdByName = "created_by_name"
        case completedAt = "completed_at"
        case completedByEmail = "completed_by_email"
        case githubIssueNumber = "github_issue_number"
        case githubIssueURL = "github_issue_url"
        case githubIssueState = "github_issue_state"
        case githubIssueError = "github_issue_error"
    }

    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        id = try c.decode(Int.self, forKey: .id)
        title = try c.decodeIfPresent(String.self, forKey: .title) ?? ""
        details = try c.decodeIfPresent(String.self, forKey: .details)
            ?? c.decodeIfPresent(String.self, forKey: .notes)
        dueDate = Self.decodeDate(c, .dueDate)
        createdAt = Self.decodeDate(c, .createdAt) ?? Date()
        createdByEmail = try c.decodeIfPresent(String.self, forKey: .createdByEmail)
            ?? c.decodeIfPresent(String.self, forKey: .createdBy)
            ?? ""
        createdByName = try c.decodeIfPresent(String.self, forKey: .createdByName)
        completed = try c.decodeIfPresent(Bool.self, forKey: .completed) ?? false
        completedAt = Self.decodeDate(c, .completedAt)
        completedByEmail = try c.decodeIfPresent(String.self, forKey: .completedByEmail)
        priority = try c.decodeIfPresent(TodoPriority.self, forKey: .priority) ?? .normal
        githubIssueNumber = try c.decodeIfPresent(Int.self, forKey: .githubIssueNumber)
        if let rawURL = try c.decodeIfPresent(String.self, forKey: .githubIssueURL) {
            githubIssueURL = URL(string: rawURL)
        } else {
            githubIssueURL = nil
        }
        githubIssueState = try c.decodeIfPresent(String.self, forKey: .githubIssueState)
        githubIssueError = try c.decodeIfPresent(String.self, forKey: .githubIssueError)
    }

    func encode(to encoder: Encoder) throws {
        var c = encoder.container(keyedBy: CodingKeys.self)
        try c.encode(id, forKey: .id)
        try c.encode(title, forKey: .title)
        try c.encodeIfPresent(details, forKey: .details)
        try c.encodeIfPresent(Self.encodeDate(dueDate), forKey: .dueDate)
        try c.encode(Self.encodeDate(createdAt), forKey: .createdAt)
        try c.encode(createdByEmail, forKey: .createdByEmail)
        try c.encodeIfPresent(createdByName, forKey: .createdByName)
        try c.encode(completed, forKey: .completed)
        try c.encodeIfPresent(Self.encodeDate(completedAt), forKey: .completedAt)
        try c.encodeIfPresent(completedByEmail, forKey: .completedByEmail)
        try c.encode(priority, forKey: .priority)
        try c.encodeIfPresent(githubIssueNumber, forKey: .githubIssueNumber)
        try c.encodeIfPresent(githubIssueURL?.absoluteString, forKey: .githubIssueURL)
        try c.encodeIfPresent(githubIssueState, forKey: .githubIssueState)
        try c.encodeIfPresent(githubIssueError, forKey: .githubIssueError)
    }

    private static func decodeDate(_ c: KeyedDecodingContainer<CodingKeys>, _ key: CodingKeys) -> Date? {
        guard let raw = try? c.decodeIfPresent(String.self, forKey: key), !raw.isEmpty else { return nil }
        if let date = ISO8601DateFormatter().date(from: raw) { return date }
        let formatter = DateFormatter()
        formatter.locale = Locale(identifier: "en_US_POSIX")
        formatter.dateFormat = "yyyy-MM-dd HH:mm:ss"
        return formatter.date(from: raw)
    }

    private static func encodeDate(_ date: Date?) -> String? {
        guard let date else { return nil }
        return ISO8601DateFormatter().string(from: date)
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
