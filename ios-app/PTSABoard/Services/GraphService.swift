import Foundation

/// Microsoft Graph helper. The shared instance is fine because each call
/// receives a fresh access token from `AuthService.graphAccessToken()`.
final class GraphService {

    static let shared = GraphService()
    private init() {}

    private let api = APIClient()
    private let graphRoot = URL(string: "https://graph.microsoft.com/v1.0")!

    // MARK: - Me

    func fetchMe(accessToken: String) async throws -> UserProfile {
        let url = graphRoot.appendingPathComponent("me")
        return try await api.request(
            url, auth: .bearer(token: accessToken), as: UserProfile.self
        )
    }

    // MARK: - Photo

    func fetchMyPhoto(accessToken: String) async -> Data? {
        let url = graphRoot.appendingPathComponent("me/photo/$value")
        return try? await api.raw(url, auth: .bearer(token: accessToken))
    }

    // MARK: - Shared calendar

    /// Read events from the shared mailbox (`Calendar@wilderptsa.net`) between
    /// `from` and `to`. Uses Graph's `calendarView` endpoint via the shared user.
    func sharedCalendarEvents(accessToken: String, from: Date, to: Date) async throws -> [GraphEvent] {
        let isoFrom = ISO8601DateFormatter().string(from: from)
        let isoTo = ISO8601DateFormatter().string(from: to)

        let mailbox = AppConfig.sharedCalendarMailbox
        let url = graphRoot
            .appendingPathComponent("users/\(mailbox)/calendarView")

        let resp: GraphEventList = try await api.request(
            url,
            query: [
                URLQueryItem(name: "startDateTime", value: isoFrom),
                URLQueryItem(name: "endDateTime", value: isoTo),
                URLQueryItem(name: "$orderby", value: "start/dateTime"),
                URLQueryItem(name: "$top", value: "200")
            ],
            auth: .bearer(token: accessToken),
            as: GraphEventList.self
        )
        return resp.value
    }

    /// Create a new event on the shared calendar. Requires Calendars.ReadWrite.Shared.
    /// Returns the freshly-created event with its server-assigned id.
    func createSharedEvent(
        accessToken: String,
        subject: String,
        bodyHTML: String,
        start: Date,
        end: Date,
        timeZone: String = TimeZone.current.identifier,
        isAllDay: Bool = false,
        location: String? = nil
    ) async throws -> GraphEvent {
        let mailbox = AppConfig.sharedCalendarMailbox
        let url = graphRoot.appendingPathComponent("users/\(mailbox)/events")

        let fmt = ISO8601DateFormatter()
        fmt.formatOptions = [.withInternetDateTime]

        var payload: [String: Any] = [
            "subject": subject,
            "body": [
                "contentType": "HTML",
                "content": bodyHTML
            ],
            "start": [
                "dateTime": fmt.string(from: start),
                "timeZone": timeZone
            ],
            "end": [
                "dateTime": fmt.string(from: end),
                "timeZone": timeZone
            ],
            "isAllDay": isAllDay
        ]
        if let location, !location.isEmpty {
            payload["location"] = ["displayName": location]
        }

        let body = try JSONSerialization.data(withJSONObject: payload)
        return try await api.request(
            url, method: "POST", body: body,
            auth: .bearer(token: accessToken), as: GraphEvent.self
        )
    }

    /// Send an email as the signed-in user.
    func sendMail(
        accessToken: String,
        subject: String,
        bodyHTML: String,
        to: [String],
        cc: [String] = []
    ) async throws {
        struct SendMailRequest: Encodable {
            let message: Message
            let saveToSentItems: Bool

            struct Message: Encodable {
                let subject: String
                let body: Body
                let toRecipients: [Recipient]
                let ccRecipients: [Recipient]
            }
            struct Body: Encodable { let contentType: String; let content: String }
            struct Recipient: Encodable { let emailAddress: Email }
            struct Email: Encodable { let address: String }
        }

        let payload = SendMailRequest(
            message: .init(
                subject: subject,
                body: .init(contentType: "HTML", content: bodyHTML),
                toRecipients: to.map { .init(emailAddress: .init(address: $0)) },
                ccRecipients: cc.map { .init(emailAddress: .init(address: $0)) }
            ),
            saveToSentItems: true
        )
        let url = graphRoot.appendingPathComponent("me/sendMail")
        let body = try JSONEncoder().encode(payload)
        _ = try await api.raw(url, method: "POST", body: body, auth: .bearer(token: accessToken))
    }
}
