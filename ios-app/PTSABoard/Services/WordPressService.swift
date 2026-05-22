import Foundation

/// WordPress core (users) and custom PTSA Tools endpoints.
///
/// For everything that mutates user accounts (password reset, role changes)
/// we authenticate against a *custom* endpoint we ship from the PTA Tools
/// WordPress plugin and authorize using the user's Entra ID JWT. See README
/// for the matching server-side endpoints — until they're deployed these
/// methods will surface 404s and the UI handles that gracefully.
final class WordPressService {

    static let shared = WordPressService()
    private init() {}

    private let api = APIClient()

    private func wpAuth() async throws -> APIClient.AuthMode {
        let token = try await AuthDelegate.shared.wordpressToken()
        return .bearer(token: token)
    }

    // MARK: - Users (search / list)

    func searchUsers(_ search: String, page: Int = 1, perPage: Int = 25) async throws -> [WPUser] {
        var query: [URLQueryItem] = [
            URLQueryItem(name: "page", value: "\(page)"),
            URLQueryItem(name: "per_page", value: "\(perPage)"),
            URLQueryItem(name: "context", value: "edit")
        ]
        if !search.isEmpty { query.append(.init(name: "search", value: search)) }

        // All user lookups go through our SSO-protected endpoint — wp/v2/users
        // is intentionally NOT used (it doesn't accept our id-token).
        let url = AppConfig.ptsaRestBase.appendingPathComponent("users")
        return try await api.request(url, query: query, auth: try await wpAuth(), as: [WPUser].self)
    }

    func fetchUser(_ id: Int) async throws -> WPUser {
        let url = AppConfig.ptsaRestBase.appendingPathComponent("users/\(id)")
        return try await api.request(url, auth: try await wpAuth(), as: WPUser.self)
    }

    func triggerPasswordReset(forEmail email: String) async throws {
        struct Req: Encodable { let email: String }
        let body = try JSONEncoder().encode(Req(email: email))
        let url = AppConfig.ptsaRestBase.appendingPathComponent("users/reset-password")
        _ = try await api.raw(url, method: "POST", body: body, auth: try await wpAuth())
    }

    /// Trigger a password reset for the currently signed-in user's own WordPress account.
    func triggerSelfPasswordReset() async throws {
        let url = AppConfig.ptsaRestBase.appendingPathComponent("users/reset-password-self")
        _ = try await api.raw(url, method: "POST", auth: try await wpAuth())
    }

    func updateUserRoles(_ id: Int, roles: [String]) async throws -> WPUser {
        struct Req: Encodable { let roles: [String] }
        let body = try JSONEncoder().encode(Req(roles: roles))
        let url = AppConfig.ptsaRestBase.appendingPathComponent("users/\(id)")
        return try await api.request(url, method: "PUT", body: body, auth: try await wpAuth(), as: WPUser.self)
    }

    // MARK: - Todo / Tech backlog (custom endpoint)

    func listTodos() async throws -> [TodoItem] {
        let url = AppConfig.ptsaRestBase.appendingPathComponent("todos")
        return try await api.request(url, auth: try await wpAuth(), as: [TodoItem].self)
    }

    func createTodo(_ item: TodoItem) async throws -> TodoItem {
        let url = AppConfig.ptsaRestBase.appendingPathComponent("todos")
        let body = try JSONEncoder.iso.encode(item)
        return try await api.request(url, method: "POST", body: body, auth: try await wpAuth(), as: TodoItem.self)
    }

    func updateTodo(_ item: TodoItem) async throws -> TodoItem {
        let url = AppConfig.ptsaRestBase.appendingPathComponent("todos/\(item.id)")
        let body = try JSONEncoder.iso.encode(item)
        return try await api.request(url, method: "PUT", body: body, auth: try await wpAuth(), as: TodoItem.self)
    }

    func deleteTodo(_ id: Int) async throws {
        let url = AppConfig.ptsaRestBase.appendingPathComponent("todos/\(id)")
        _ = try await api.raw(url, method: "DELETE", auth: try await wpAuth())
    }

    // MARK: - Auction emails

    /// Server-side endpoint should pull the latest auction items and email
    /// the resulting bulletin to `to`. We pass an optional subject override.
    func sendAuctionItemsEmail(to: [String], subject: String?) async throws {
        struct Req: Encodable { let to: [String]; let subject: String? }
        let body = try JSONEncoder().encode(Req(to: to, subject: subject))
        let url = AppConfig.ptsaRestBase.appendingPathComponent("auction/email-items")
        _ = try await api.raw(url, method: "POST", body: body, auth: try await wpAuth())
    }
}

extension JSONEncoder {
    static let iso: JSONEncoder = {
        let e = JSONEncoder()
        e.dateEncodingStrategy = .iso8601
        return e
    }()
}

extension JSONDecoder {
    static let iso: JSONDecoder = {
        let d = JSONDecoder()
        d.dateDecodingStrategy = .iso8601
        return d
    }()
}
