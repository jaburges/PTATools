import Foundation

struct WPRole: Codable, Identifiable, Hashable {
    var slug: String
    var name: String

    var id: String { slug }
}

struct PTAOrgResponse: Decodable {
    var departments: [PTADepartment]
    var users: [PTAUserIdentity]
}

struct PTADepartment: Decodable, Identifiable, Hashable {
    var id: Int
    var name: String
    var slug: String?
    var description: String?
    var vp_user_id: Int?
    var vp_user: PTAUserIdentity?
    var roles: [PTARole]
}

struct PTARole: Decodable, Identifiable, Hashable {
    var id: Int
    var department_id: Int
    var name: String
    var slug: String?
    var description: String?
    var max_occupants: Int
    var assigned_count: Int
    var vacancy_count: Int
    var status: String?
    var assignments: [PTAAssignment]
}

struct PTAAssignment: Decodable, Identifiable, Hashable {
    var id: Int
    var role_id: Int
    var user_id: Int
    var is_primary: Bool
    var status: String
    var user: PTAUserIdentity?
}

struct PTAUserIdentity: Decodable, Identifiable, Hashable {
    var id: Int
    var email: String?
    var display_name: String
    var username: String?

    var displayName: String {
        display_name.isEmpty ? (username ?? "User #\(id)") : display_name
    }
}
