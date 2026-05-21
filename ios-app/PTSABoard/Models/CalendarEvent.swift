import Foundation

/// Microsoft Graph Calendar event (subset of the OData entity we care about).
struct GraphEvent: Codable, Identifiable, Hashable {
    let id: String
    var subject: String?
    var bodyPreview: String?
    var start: GraphDateTimeTZ
    var end: GraphDateTimeTZ
    var location: GraphLocation?
    var isAllDay: Bool?
    var organizer: GraphAttendee?
    var attendees: [GraphAttendee]?
    var webLink: String?

    var startDate: Date? { start.asDate }
    var endDate: Date?   { end.asDate }
}

struct GraphDateTimeTZ: Codable, Hashable {
    var dateTime: String
    var timeZone: String

    /// Convert to a Swift Date by parsing the Graph-style string.
    var asDate: Date? {
        let fmt = ISO8601DateFormatter()
        fmt.formatOptions = [.withInternetDateTime, .withFractionalSeconds]
        if let d = fmt.date(from: dateTime + (dateTime.hasSuffix("Z") ? "" : "Z")) {
            return d
        }
        let fb = DateFormatter()
        fb.locale = Locale(identifier: "en_US_POSIX")
        fb.dateFormat = "yyyy-MM-dd'T'HH:mm:ss.SSSSSSS"
        return fb.date(from: dateTime)
    }
}

struct GraphLocation: Codable, Hashable {
    var displayName: String?
    var address: GraphAddress?
}

struct GraphAddress: Codable, Hashable {
    var street: String?
    var city: String?
    var state: String?
    var countryOrRegion: String?
    var postalCode: String?
}

struct GraphAttendee: Codable, Hashable {
    var emailAddress: GraphEmailAddress?
    var type: String?
    var status: GraphResponseStatus?
}

struct GraphEmailAddress: Codable, Hashable {
    var name: String?
    var address: String?
}

struct GraphResponseStatus: Codable, Hashable {
    var response: String?
    var time: String?
}

struct GraphEventList: Codable {
    let value: [GraphEvent]
}
