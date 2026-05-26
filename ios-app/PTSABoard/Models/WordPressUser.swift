import Foundation

struct WPUser: Decodable, Identifiable, Hashable {
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

    enum CodingKeys: String, CodingKey {
        case id, name, display_name, username, first_name, last_name
        case email, roles, registered_date, avatar_urls
    }

    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        id = try c.decode(Int.self, forKey: .id)
        name = try c.decodeIfPresent(String.self, forKey: .name)
            ?? c.decodeIfPresent(String.self, forKey: .display_name)
            ?? ""
        username = try c.decodeIfPresent(String.self, forKey: .username)
        first_name = try c.decodeIfPresent(String.self, forKey: .first_name)
        last_name = try c.decodeIfPresent(String.self, forKey: .last_name)
        email = try c.decodeIfPresent(String.self, forKey: .email)
        roles = try c.decodeIfPresent([String].self, forKey: .roles)
        registered_date = try c.decodeIfPresent(String.self, forKey: .registered_date)
        avatar_urls = try c.decodeIfPresent([String: String].self, forKey: .avatar_urls)
    }
}
