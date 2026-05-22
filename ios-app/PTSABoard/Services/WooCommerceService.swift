import Foundation
import UIKit

/// WooCommerce-facing operations, ALL proxied through our own
/// `/wp-json/ptsa/v1/*` endpoints. The signed-in user's Entra id-token is
/// validated by the WordPress plugin server-side and the WooCommerce
/// internals run as the matching WP user — no consumer key/secret needed.
final class WooCommerceService {

    static let shared = WooCommerceService()
    private init() {}

    private let api = APIClient()

    private func wpAuth() async throws -> APIClient.AuthMode {
        let token = try await AuthDelegate.shared.wordpressToken()
        return .bearer(token: token)
    }

    // MARK: - Orders

    func recentOrders(
        page: Int = 1,
        perPage: Int = 25,
        status: String? = nil,
        search: String? = nil
    ) async throws -> [WCOrder] {
        var query: [URLQueryItem] = [
            URLQueryItem(name: "page", value: "\(page)"),
            URLQueryItem(name: "per_page", value: "\(perPage)")
        ]
        if let status, !status.isEmpty, status != "any" {
            query.append(.init(name: "status", value: status))
        }
        if let search, !search.isEmpty {
            query.append(.init(name: "search", value: search))
        }
        let url = AppConfig.ptsaRestBase.appendingPathComponent("orders")
        return try await api.request(url, query: query, auth: try await wpAuth(), as: [WCOrder].self)
    }

    func fetchOrder(_ orderId: Int) async throws -> WCOrder {
        let url = AppConfig.ptsaRestBase.appendingPathComponent("orders/\(orderId)")
        return try await api.request(url, auth: try await wpAuth(), as: WCOrder.self)
    }

    func updateOrderStatus(_ orderId: Int, to status: String) async throws -> WCOrder {
        struct Patch: Encodable { let status: String }
        let body = try JSONEncoder().encode(Patch(status: status))
        let url = AppConfig.ptsaRestBase.appendingPathComponent("orders/\(orderId)")
        return try await api.request(url, method: "PUT", body: body, auth: try await wpAuth(), as: WCOrder.self)
    }

    func refundOrder(_ orderId: Int, amount: Double, reason: String?) async throws {
        struct RefundReq: Encodable { let amount: Double; let reason: String?; let api_refund: Bool }
        let body = try JSONEncoder().encode(
            RefundReq(amount: amount, reason: reason, api_refund: true)
        )
        let url = AppConfig.ptsaRestBase.appendingPathComponent("orders/\(orderId)/refunds")
        _ = try await api.raw(url, method: "POST", body: body, auth: try await wpAuth())
    }

    func addOrderNote(_ orderId: Int, note: String, customerVisible: Bool) async throws {
        struct NoteReq: Encodable { let note: String; let customer_note: Bool }
        let body = try JSONEncoder().encode(NoteReq(note: note, customer_note: customerVisible))
        let url = AppConfig.ptsaRestBase.appendingPathComponent("orders/\(orderId)/notes")
        _ = try await api.raw(url, method: "POST", body: body, auth: try await wpAuth())
    }

    // MARK: - Products

    func products(
        page: Int = 1,
        perPage: Int = 50,
        search: String? = nil,
        type: String? = nil,
        status: String? = nil
    ) async throws -> [WCProduct] {
        var query: [URLQueryItem] = [
            URLQueryItem(name: "page", value: "\(page)"),
            URLQueryItem(name: "per_page", value: "\(perPage)")
        ]
        if let search, !search.isEmpty { query.append(.init(name: "search", value: search)) }
        if let type, !type.isEmpty, type != "any" { query.append(.init(name: "type", value: type)) }
        if let status, !status.isEmpty, status != "any" { query.append(.init(name: "status", value: status)) }
        let url = AppConfig.ptsaRestBase.appendingPathComponent("products")
        return try await api.request(url, query: query, auth: try await wpAuth(), as: [WCProduct].self)
    }

    func updateProduct(_ id: Int, patch: [String: Any]) async throws -> WCProduct {
        let body = try JSONSerialization.data(withJSONObject: patch)
        let url = AppConfig.ptsaRestBase.appendingPathComponent("products/\(id)")
        return try await api.request(url, method: "PUT", body: body, auth: try await wpAuth(), as: WCProduct.self)
    }

    func createProduct(_ patch: [String: Any]) async throws -> WCProduct {
        let body = try JSONSerialization.data(withJSONObject: patch)
        let url = AppConfig.ptsaRestBase.appendingPathComponent("products")
        return try await api.request(url, method: "POST", body: body, auth: try await wpAuth(), as: WCProduct.self)
    }

    // MARK: - Media (product images)

    /// Upload an image to WordPress media library via the PTSA REST proxy.
    /// The plugin endpoint validates our Entra id-token and runs the upload
    /// as the signed-in WP user, so no consumer keys / app passwords are
    /// needed.
    func uploadImage(_ image: UIImage, filename: String = "product.jpg") async throws -> WCImage {
        guard let jpeg = image.jpegData(compressionQuality: 0.85) else {
            throw APIError.transport(NSError(domain: "Woo", code: -1, userInfo: [NSLocalizedDescriptionKey: "Could not encode image"]))
        }
        let url = AppConfig.ptsaRestBase.appendingPathComponent("media")
        var req = URLRequest(url: url)
        req.httpMethod = "POST"
        req.setValue("image/jpeg", forHTTPHeaderField: "Content-Type")
        req.setValue("attachment; filename=\"\(filename)\"", forHTTPHeaderField: "Content-Disposition")
        let token = try await AuthDelegate.shared.wordpressToken()
        req.setValue("Bearer \(token)", forHTTPHeaderField: "Authorization")
        req.httpBody = jpeg

        let (data, resp) = try await URLSession.shared.data(for: req)
        guard let http = resp as? HTTPURLResponse, (200..<300).contains(http.statusCode) else {
            let code = (resp as? HTTPURLResponse)?.statusCode ?? -1
            throw APIError.http(code, String(data: data.prefix(400), encoding: .utf8))
        }
        return try JSONDecoder().decode(WCImage.self, from: data)
    }
}

/// Tiny adapter so non-MainActor services can ask for tokens without
/// keeping a reference to AuthService directly. Set on app launch.
///
/// The app issues **two** distinct tokens from the same MSAL sign-in:
///   • `graphToken()`     — `accessToken`, audience = Microsoft Graph.
///                          Use for graph.microsoft.com calls.
///   • `wordpressToken()` — `idToken`, audience = our Entra client_id.
///                          Use for our own /wp-json/ptsa/v1/* endpoints
///                          (the WordPress plugin validates the JWT).
@MainActor
final class AuthDelegate {
    static let shared = AuthDelegate()

    var graphTokenProvider: (() async throws -> String)?
    var wordpressTokenProvider: (() async throws -> String)?

    func graphToken() async throws -> String {
        guard let p = graphTokenProvider else {
            throw APIError.notConfigured("AuthDelegate.graphTokenProvider")
        }
        return try await p()
    }

    func wordpressToken() async throws -> String {
        guard let p = wordpressTokenProvider else {
            throw APIError.notConfigured("AuthDelegate.wordpressTokenProvider")
        }
        return try await p()
    }
}
