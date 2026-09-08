import SwiftUI

/// Catalog of Home-screen widgets. Raw values must match
/// `Azure_PTSA_REST_API::HOME_WIDGET_IDS` so prefs sync to WordPress.
enum HomeWidgetID: String, CaseIterable, Codable, Identifiable, Hashable {
    case orders
    case calendar
    case volunteers
    case memberships
    case products
    case users
    case ptaRoles = "pta_roles"
    case backlog

    var id: String { rawValue }

    var title: String {
        switch self {
        case .orders: return "Orders"
        case .calendar: return "Calendar"
        case .volunteers: return "Volunteer sign-up"
        case .memberships: return "Memberships"
        case .products: return "Products"
        case .users: return "Users"
        case .ptaRoles: return "PTA roles"
        case .backlog: return "Tech backlog"
        }
    }

    var subtitle: String {
        switch self {
        case .orders: return "Open shop orders"
        case .calendar: return "Upcoming events"
        case .volunteers: return "Sheets and open slots"
        case .memberships: return "Paid members this year"
        case .products: return "Catalog and inventory"
        case .users: return "WordPress accounts"
        case .ptaRoles: return "Board seats and vacancies"
        case .backlog: return "Shared technology list"
        }
    }

    var systemImage: String {
        switch self {
        case .orders: return "bag.fill"
        case .calendar: return "calendar"
        case .volunteers: return "hands.sparkles.fill"
        case .memberships: return "person.crop.rectangle.stack.fill"
        case .products: return "cube.box.fill"
        case .users: return "person.2.fill"
        case .ptaRoles: return "person.3.sequence.fill"
        case .backlog: return "list.clipboard.fill"
        }
    }

    var accent: Color {
        switch self {
        case .orders: return .blue
        case .calendar: return .indigo
        case .volunteers: return .orange
        case .memberships: return .green
        case .products: return .purple
        case .users: return .teal
        case .ptaRoles: return .pink
        case .backlog: return .gray
        }
    }

    /// Shown until the user customizes. Tabs already cover products/users/backlog.
    var defaultVisible: Bool {
        switch self {
        case .products, .users, .backlog: return false
        default: return true
        }
    }
}

struct HomeWidgetPrefs: Codable, Equatable {
    var order: [HomeWidgetID]
    var hidden: [HomeWidgetID]
    var updated: Int

    static var defaults: HomeWidgetPrefs {
        HomeWidgetPrefs(
            order: HomeWidgetID.allCases,
            hidden: HomeWidgetID.allCases.filter { !$0.defaultVisible },
            updated: 0
        )
    }

    var visible: [HomeWidgetID] {
        order.filter { !hidden.contains($0) }
    }

    enum CodingKeys: String, CodingKey {
        case order, hidden, updated
    }

    init(order: [HomeWidgetID], hidden: [HomeWidgetID], updated: Int) {
        self.order = Self.complete(order)
        self.hidden = hidden
        self.updated = updated
    }

    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        let rawOrder = try c.decodeIfPresent([String].self, forKey: .order) ?? []
        let rawHidden = try c.decodeIfPresent([String].self, forKey: .hidden) ?? []
        order = Self.complete(rawOrder.compactMap { HomeWidgetID(rawValue: $0) })
        hidden = rawHidden.compactMap { HomeWidgetID(rawValue: $0) }
        updated = try c.decodeIfPresent(Int.self, forKey: .updated) ?? 0
    }

    private static func complete(_ order: [HomeWidgetID]) -> [HomeWidgetID] {
        var out = order
        for id in HomeWidgetID.allCases where !out.contains(id) {
            out.append(id)
        }
        return out
    }
}
