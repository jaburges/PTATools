import Foundation

/// One row from `tec_calendar_mappings` as exposed by `/ptsa/v1/calendars`.
/// Represents a single Outlook calendar (e.g. `Calendar@wilderptsa.net` or
/// `art@wilderptsa.net`) that the PTA Tools Calendars module is syncing
/// into the `pta_event` CPT.
struct CalendarMapping: Codable, Identifiable, Hashable {
    let id: Int
    let calendarId: String          // outlook_calendar_id (UPN or Graph cal id)
    let name: String                // "Events", "Art", etc.
    let categoryId: Int?
    let categoryName: String
    let syncEnabled: Bool
    let lastSync: String?
    let eventCount: Int

    enum CodingKeys: String, CodingKey {
        case id
        case calendarId    = "calendar_id"
        case name
        case categoryId    = "category_id"
        case categoryName  = "category_name"
        case syncEnabled   = "sync_enabled"
        case lastSync      = "last_sync"
        case eventCount    = "event_count"
    }
}

/// One event returned by `/ptsa/v1/events` — a `pta_event` post enriched
/// with its source calendar and TEC-compatible meta keys.
struct PtaEvent: Codable, Identifiable, Hashable {
    let id: Int
    let subject: String
    let bodyPreview: String?
    let permalink: String?
    let start: Date?
    let end: Date?
    let allDay: Bool
    let location: String?
    let calendarId: String
    let calendarName: String
    let outlookEventId: String?

    enum CodingKeys: String, CodingKey {
        case id
        case subject
        case bodyPreview     = "body_preview"
        case permalink
        case start
        case end
        case allDay          = "all_day"
        case location
        case calendarId      = "calendar_id"
        case calendarName    = "calendar_name"
        case outlookEventId  = "outlook_event_id"
    }

    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        self.id = try c.decode(Int.self, forKey: .id)
        self.subject = try c.decode(String.self, forKey: .subject)
        self.bodyPreview = try c.decodeIfPresent(String.self, forKey: .bodyPreview)
        self.permalink = try c.decodeIfPresent(String.self, forKey: .permalink)
        self.start = PtaEvent.parseDate(try c.decodeIfPresent(String.self, forKey: .start))
        self.end = PtaEvent.parseDate(try c.decodeIfPresent(String.self, forKey: .end))
        self.allDay = try c.decodeIfPresent(Bool.self, forKey: .allDay) ?? false
        self.location = try c.decodeIfPresent(String.self, forKey: .location)
        self.calendarId = try c.decodeIfPresent(String.self, forKey: .calendarId) ?? ""
        self.calendarName = try c.decodeIfPresent(String.self, forKey: .calendarName) ?? ""
        self.outlookEventId = try c.decodeIfPresent(String.self, forKey: .outlookEventId)
    }

    /// Custom encoder — we round-trip dates as ISO-8601 strings so the
    /// model can be re-encoded and stored locally without losing precision.
    func encode(to encoder: Encoder) throws {
        var c = encoder.container(keyedBy: CodingKeys.self)
        try c.encode(id, forKey: .id)
        try c.encode(subject, forKey: .subject)
        try c.encodeIfPresent(bodyPreview, forKey: .bodyPreview)
        try c.encodeIfPresent(permalink, forKey: .permalink)
        try c.encodeIfPresent(start.flatMap(PtaEvent.atomFormatter.string(from:)), forKey: .start)
        try c.encodeIfPresent(end.flatMap(PtaEvent.atomFormatter.string(from:)), forKey: .end)
        try c.encode(allDay, forKey: .allDay)
        try c.encodeIfPresent(location, forKey: .location)
        try c.encode(calendarId, forKey: .calendarId)
        try c.encode(calendarName, forKey: .calendarName)
        try c.encodeIfPresent(outlookEventId, forKey: .outlookEventId)
    }

    // MARK: - Date parsing
    private static let atomFormatter: ISO8601DateFormatter = {
        let f = ISO8601DateFormatter()
        f.formatOptions = [.withInternetDateTime]
        return f
    }()
    private static let fractionalFormatter: ISO8601DateFormatter = {
        let f = ISO8601DateFormatter()
        f.formatOptions = [.withInternetDateTime, .withFractionalSeconds]
        return f
    }()

    private static func parseDate(_ s: String?) -> Date? {
        guard let s, !s.isEmpty else { return nil }
        return atomFormatter.date(from: s) ?? fractionalFormatter.date(from: s)
    }
}
