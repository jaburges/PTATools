import Foundation

struct UserProfile: Codable, Hashable {
    let id: String
    let displayName: String
    let userPrincipalName: String
    let mail: String?
    let jobTitle: String?
    let givenName: String?
    let surname: String?

    var email: String { mail ?? userPrincipalName }

    var initials: String {
        let parts = displayName.split(separator: " ").prefix(2)
        let initials = parts.compactMap { $0.first }.map { String($0) }.joined()
        return initials.isEmpty ? String(email.prefix(2)).uppercased() : initials.uppercased()
    }

    var isTodoAdmin: Bool {
        AppConfig.todoAdminEmails.contains(email.lowercased())
    }
}
