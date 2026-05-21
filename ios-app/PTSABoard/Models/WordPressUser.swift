import Foundation

struct WPUser: Codable, Identifiable, Hashable {
    let id: Int
    let name: String
    let username: String?
    let first_name: String?
    let last_name: String?
    let email: String?
    let roles: [String]?
    let registered_date: String?
    let avatar_urls: [String: String]?

    var displayName: String {
        if !name.isEmpty { return name }
        let joined = [first_name, last_name].compactMap { $0 }.joined(separator: " ")
        return joined.isEmpty ? (username ?? "User #\(id)") : joined
    }

    var avatarURL: URL? {
        let preferred = ["96", "48", "24"]
        for key in preferred {
            if let u = avatar_urls?[key], let url = URL(string: u) {
                return url
            }
        }
        return nil
    }

    var roleLabel: String {
        (roles ?? []).map { $0.replacingOccurrences(of: "_", with: " ").capitalized }.joined(separator: ", ")
    }
}
