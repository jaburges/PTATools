import Foundation
import UIKit

final class WooCommerceService {

    static let shared = WooCommerceService()
    private init() {}

    private let api = APIClient()

    private var auth: APIClient.AuthMode {
        guard !AppConfig.wooConsumerKey.hasPrefix("REPLACE_") else {
            return .none
        }
        return .basic(user: AppConfig.wooConsumerKey, pass: AppConfig.wooConsumerSecret)
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
            URLQueryItem(name: "per_page", value: "\(perPage)"),
            URLQueryItem(name: "orderby", value: "date"),
            URLQueryItem(name: "order", value: "desc")
        ]
        if let status, !status.isEmpty, status != "any" {
            query.append(.init(name: "status", value: status))
        }
        if let search, !search.isEmpty {
            query.append(.init(name: "search", value: search))
        }
        let url = AppConfig.wcRestBase.appendingPathComponent("orders")
        return try await api.request(url, query: query, auth: auth, as: [WCOrder].self)
    }

    func fetchOrder(_ orderId: Int) async throws -> WCOrder {
        let url = AppConfig.wcRestBase.appendingPathComponent("orders/\(orderId)")
        return try await api.request(url, auth: auth, as: WCOrder.self)
    }

    func updateOrderStatus(_ orderId: Int, to status: String) async throws -> WCOrder {
        struct Patch: Encodable { let status: String }
        let body = try JSONEncoder().encode(Patch(status: status))
        let url = AppConfig.wcRestBase.appendingPathComponent("orders/\(orderId)")
        return try await api.request(url, method: "PUT", body: body, auth: auth, as: WCOrder.self)
    }

    func refundOrder(_ orderId: Int, amount: Double, reason: String?) async throws {
        struct RefundReq: Encodable { let amount: String; let reason: String?; let api_refund: Bool }
        let body = try JSONEncoder().encode(
            RefundReq(amount: String(format: "%.2f", amount), reason: reason, api_refund: true)
        )
        let url = AppConfig.wcRestBase.appendingPathComponent("orders/\(orderId)/refunds")
        _ = try await api.raw(url, method: "POST", body: body, auth: auth)
    }

    func addOrderNote(_ orderId: Int, note: String, customerVisible: Bool) async throws {
        struct NoteReq: Encodable { let note: String; let customer_note: Bool }
        let body = try JSONEncoder().encode(NoteReq(note: note, customer_note: customerVisible))
        let url = AppConfig.wcRestBase.appendingPathComponent("orders/\(orderId)/notes")
        _ = try await api.raw(url, method: "POST", body: body, auth: auth)
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
            URLQueryItem(name: "per_page", value: "\(perPage)"),
            URLQueryItem(name: "orderby", value: "date"),
            URLQueryItem(name: "order", value: "desc")
        ]
        if let search, !search.isEmpty { query.append(.init(name: "search", value: search)) }
        if let type, !type.isEmpty, type != "any" { query.append(.init(name: "type", value: type)) }
        if let status, !status.isEmpty, status != "any" { query.append(.init(name: "status", value: status)) }
        let url = AppConfig.wcRestBase.appendingPathComponent("products")
        return try await api.request(url, query: query, auth: auth, as: [WCProduct].self)
    }

    func updateProduct(_ id: Int, patch: [String: Any]) async throws -> WCProduct {
        let body = try JSONSerialization.data(withJSONObject: patch)
        let url = AppConfig.wcRestBase.appendingPathComponent("products/\(id)")
        return try await api.request(url, method: "PUT", body: body, auth: auth, as: WCProduct.self)
    }

    func createProduct(_ patch: [String: Any]) async throws -> WCProduct {
        let body = try JSONSerialization.data(withJSONObject: patch)
        let url = AppConfig.wcRestBase.appendingPathComponent("products")
        return try await api.request(url, method: "POST", body: body, auth: auth, as: WCProduct.self)
    }

    // MARK: - Media (product images)

    /// Upload an image to WordPress media library using app-password Basic auth
    /// (re-using WC consumer key/secret only works for WC endpoints, so we hit
    /// the WP REST media endpoint via a custom plugin proxy when available,
    /// otherwise this requires an application password — see README).
    func uploadImage(_ image: UIImage, filename: String = "product.jpg") async throws -> WCImage {
        guard let jpeg = image.jpegData(compressionQuality: 0.85) else {
            throw APIError.transport(NSError(domain: "Woo", code: -1, userInfo: [NSLocalizedDescriptionKey: "Could not encode image"]))
        }
        let url = AppConfig.ptsaRestBase.appendingPathComponent("media")
        var req = URLRequest(url: url)
        req.httpMethod = "POST"
        req.setValue("image/jpeg", forHTTPHeaderField: "Content-Type")
        req.setValue("attachment; filename=\"\(filename)\"", forHTTPHeaderField: "Content-Disposition")
        // Plugin endpoint validates the bearer token via Entra ID JWT (see plugin docs in README).
        if let token = try? await AuthDelegate.shared.token() {
            req.setValue("Bearer \(token)", forHTTPHeaderField: "Authorization")
        }
        req.httpBody = jpeg

        let (data, resp) = try await URLSession.shared.data(for: req)
        guard let http = resp as? HTTPURLResponse, (200..<300).contains(http.statusCode) else {
            let code = (resp as? HTTPURLResponse)?.statusCode ?? -1
            throw APIError.http(code, String(data: data.prefix(400), encoding: .utf8))
        }
        return try JSONDecoder().decode(WCImage.self, from: data)
    }
}

/// Tiny adapter so non-MainActor services can ask for an access token without
/// keeping a reference to AuthService directly. Set on app launch.
@MainActor
final class AuthDelegate {
    static let shared = AuthDelegate()
    var tokenProvider: (() async throws -> String)?

    func token() async throws -> String {
        guard let p = tokenProvider else {
            throw APIError.notConfigured("AuthDelegate.tokenProvider")
        }
        return try await p()
    }
}
