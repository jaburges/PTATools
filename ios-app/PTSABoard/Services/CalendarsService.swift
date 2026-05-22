import Foundation

/// Read-side access to the PTSA Calendars module's data, surfaced through
/// the WordPress plugin's `/wp-json/ptsa/v1/calendars` and `/events`
/// endpoints. The signed-in user's Entra id-token authorizes every call.
final class CalendarsService {

    static let shared = CalendarsService()
    private init() {}

    private let api = APIClient()

    private func auth() async throws -> APIClient.AuthMode {
        let token = try await AuthDelegate.shared.wordpressToken()
        return .bearer(token: token)
    }

    /// All Outlook calendars known to the PTA Tools Calendars module
    /// (whether currently sync-enabled or not). The UI typically filters
    /// to `sync_enabled == true` and shows the rest behind a toggle.
    func mappings() async throws -> [CalendarMapping] {
        let url = AppConfig.ptsaRestBase.appendingPathComponent("calendars")
        return try await api.request(url, auth: try await auth(), as: [CalendarMapping].self)
    }

    /// Events from `pta_event` posts in [from, to], optionally restricted
    /// to a set of `outlook_calendar_id` values (empty = all).
    func events(
        from: Date,
        to: Date,
        calendarIds: [String] = [],
        perPage: Int = 200
    ) async throws -> [PtaEvent] {
        var query: [URLQueryItem] = [
            URLQueryItem(name: "from", value: Self.q.string(from: from)),
            URLQueryItem(name: "to",   value: Self.q.string(from: to)),
            URLQueryItem(name: "per_page", value: "\(perPage)")
        ]
        if !calendarIds.isEmpty {
            query.append(.init(name: "calendar_ids", value: calendarIds.joined(separator: ",")))
        }
        let url = AppConfig.ptsaRestBase.appendingPathComponent("events")
        return try await api.request(url, query: query, auth: try await auth(), as: [PtaEvent].self)
    }

    private static let q: ISO8601DateFormatter = {
        let f = ISO8601DateFormatter()
        f.formatOptions = [.withInternetDateTime]
        return f
    }()
}
