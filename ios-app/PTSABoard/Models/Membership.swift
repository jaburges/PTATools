import Foundation

struct MembershipSummary: Decodable, Hashable {
    let year: String
    let from: String?
    let to: String?
    let total: Int
    let counts: Counts

    struct Counts: Decodable, Hashable {
        let family: Int
        let individual: Int
        let staff: Int
        let other: Int
    }

    var headline: String {
        "\(total) member\(total == 1 ? "" : "s") · \(year)"
    }
}

struct MembershipChild: Decodable, Hashable {
    let name: String?
    let grade: String?

    var label: String {
        let n = (name ?? "").trimmingCharacters(in: .whitespaces)
        let g = (grade ?? "").trimmingCharacters(in: .whitespaces)
        if n.isEmpty { return g }
        if g.isEmpty { return n }
        return "\(n) (grade \(g))"
    }
}

struct MembershipMember: Decodable, Identifiable, Hashable {
    let user_id: Int
    let name: String
    let email: String?
    let role_types: [String]?
    let membership: String
    let paid_at: String?
    let children: [MembershipChild]?

    var id: Int { user_id }

    var membershipLabel: String {
        membership.replacingOccurrences(of: "_", with: " ").capitalized
    }

    var childrenLabel: String {
        let kids = (children ?? []).map(\.label).filter { !$0.isEmpty }
        return kids.joined(separator: ", ")
    }
}
