import Foundation

enum APIError: LocalizedError {
    case http(Int, String?)
    case decode(String)
    case transport(Error)
    case notConfigured(String)

    var errorDescription: String? {
        switch self {
        case .http(let code, let body): return "HTTP \(code)\(body.map { ": \($0)" } ?? "")"
        case .decode(let why):          return "Decoding failed: \(why)"
        case .transport(let err):       return err.localizedDescription
        case .notConfigured(let key):   return "Not configured: \(key)"
        }
    }
}

/// Lightweight URLSession-backed JSON client. Designed to be reused
/// by WooCommerce, WordPress and Graph services.
struct APIClient {

    enum AuthMode {
        case none
        case bearer(token: String)
        case basic(user: String, pass: String)
    }

    let session: URLSession

    init(session: URLSession = .shared) { self.session = session }

    func request<T: Decodable>(
        _ url: URL,
        method: String = "GET",
        query: [URLQueryItem] = [],
        body: Data? = nil,
        contentType: String = "application/json",
        auth: AuthMode = .none,
        as type: T.Type = T.self,
        decoder: JSONDecoder = APIClient.defaultDecoder
    ) async throws -> T {
        let data = try await raw(
            url, method: method, query: query, body: body,
            contentType: contentType, auth: auth
        )
        do { return try decoder.decode(T.self, from: data) }
        catch {
            let snippet = String(data: data.prefix(400), encoding: .utf8) ?? ""
            throw APIError.decode("\(error) — body: \(snippet)")
        }
    }

    @discardableResult
    func raw(
        _ url: URL,
        method: String = "GET",
        query: [URLQueryItem] = [],
        body: Data? = nil,
        contentType: String = "application/json",
        auth: AuthMode = .none
    ) async throws -> Data {
        var comps = URLComponents(url: url, resolvingAgainstBaseURL: false)!
        if !query.isEmpty {
            var items = comps.queryItems ?? []
            items.append(contentsOf: query)
            comps.queryItems = items
        }
        var req = URLRequest(url: comps.url!)
        req.httpMethod = method
        req.httpBody = body
        if body != nil { req.setValue(contentType, forHTTPHeaderField: "Content-Type") }
        req.setValue("application/json", forHTTPHeaderField: "Accept")
        req.setValue("PTSABoard-iOS/1.0", forHTTPHeaderField: "User-Agent")

        switch auth {
        case .none: break
        case .bearer(let token):
            req.setValue("Bearer \(token)", forHTTPHeaderField: "Authorization")
        case .basic(let user, let pass):
            let raw = "\(user):\(pass)".data(using: .utf8) ?? Data()
            req.setValue("Basic \(raw.base64EncodedString())", forHTTPHeaderField: "Authorization")
        }

        do {
            let (data, resp) = try await session.data(for: req)
            guard let http = resp as? HTTPURLResponse else {
                throw APIError.transport(NSError(domain: "APIClient", code: -1))
            }
            guard (200..<300).contains(http.statusCode) else {
                let snippet = String(data: data.prefix(800), encoding: .utf8)
                throw APIError.http(http.statusCode, snippet)
            }
            return data
        } catch let err as APIError {
            throw err
        } catch {
            throw APIError.transport(error)
        }
    }

    static let defaultDecoder: JSONDecoder = {
        let d = JSONDecoder()
        d.keyDecodingStrategy = .useDefaultKeys
        return d
    }()
}
