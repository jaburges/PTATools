import SwiftUI

private enum HomeRoute: Hashable {
    case orders
    case calendar
    case volunteers
    case volunteerSheet(Int)
    case memberships
    case products
    case users
    case ptaRoles
    case backlog
}

struct HomeView: View {
    @EnvironmentObject var auth: AuthService
    @EnvironmentObject var widgets: HomeWidgetStore

    @State private var showCustomize = false
    @State private var loading = false
    @State private var orders: [WCOrder] = []
    @State private var events: [SharedGraphCalendarEvent] = []
    @State private var sheets: [VolunteerSheetSummary] = []
    @State private var memberships: MembershipSummary?
    @State private var membershipError: String?
    @State private var vacancies = 0
    @State private var openTodos = 0

    var body: some View {
        ScrollView {
            LazyVStack(spacing: 14) {
                if widgets.visible.isEmpty {
                    EmptyStateView(
                        systemImage: "rectangle.stack.badge.minus",
                        title: "No widgets",
                        message: "Tap Edit to choose what shows on your Home page."
                    )
                    .padding(.top, 40)
                } else {
                    ForEach(widgets.visible) { id in
                        widgetCard(id)
                    }
                }
            }
            .padding(.horizontal)
            .padding(.vertical, 12)
        }
        .background(Color(.systemGroupedBackground))
        .navigationTitle("Home")
        .navigationDestination(for: HomeRoute.self) { route in
            switch route {
            case .orders: OrdersView()
            case .calendar: CalendarView()
            case .volunteers: VolunteersView()
            case .volunteerSheet(let id): VolunteerSheetDetailView(sheetId: id)
            case .memberships: MembershipsView()
            case .products: ProductsView()
            case .users: UsersView()
            case .ptaRoles: PTARolesView()
            case .backlog: TodoView()
            }
        }
        .toolbar {
            ToolbarItem(placement: .topBarLeading) {
                Button("Edit") { showCustomize = true }
            }
        }
        .sheet(isPresented: $showCustomize) {
            NavigationStack {
                HomeWidgetCustomizeView()
                    .environmentObject(widgets)
            }
        }
        .refreshable { await load() }
        .task { await load() }
        .onChange(of: auth.wpRoles) { _, _ in
            Task { await loadMemberships() }
        }
        .overlay {
            if loading && widgets.visible.isEmpty == false && orders.isEmpty && sheets.isEmpty && events.isEmpty {
                ProgressView().controlSize(.large)
            }
        }
    }

    @ViewBuilder
    private func widgetCard(_ id: HomeWidgetID) -> some View {
        NavigationLink(value: route(for: id)) {
            HomeWidgetCard(id: id) {
                widgetBody(id)
            }
        }
        .buttonStyle(.plain)
    }

    private func route(for id: HomeWidgetID) -> HomeRoute {
        switch id {
        case .orders: return .orders
        case .calendar: return .calendar
        case .volunteers: return .volunteers
        case .memberships: return .memberships
        case .products: return .products
        case .users: return .users
        case .ptaRoles: return .ptaRoles
        case .backlog: return .backlog
        }
    }

    @ViewBuilder
    private func widgetBody(_ id: HomeWidgetID) -> some View {
        switch id {
        case .orders:
            widgetLines(orders.prefix(3).map { "#\($0.number) · \($0.customerName)" }, empty: "No recent orders")
        case .calendar:
            widgetLines(events.prefix(3).map(\.subject), empty: "No upcoming events")
        case .volunteers:
            widgetLines(
                sheets.prefix(3).map { "\($0.title) · \($0.fillLabel)" },
                empty: "No volunteer sheets"
            )
        case .memberships:
            if let memberships {
                Text(memberships.headline).font(.subheadline.weight(.semibold))
                Text("Family \(memberships.counts.family) · Individual \(memberships.counts.individual)")
                    .font(.caption)
                    .foregroundStyle(.secondary)
            } else {
                Text(membershipError ?? (auth.canReadMemberships ? "Loading…" : "Finance role required"))
                    .font(.subheadline)
                    .foregroundStyle(.secondary)
            }
        case .products:
            Text("Open the catalog")
                .font(.subheadline)
                .foregroundStyle(.secondary)
        case .users:
            Text("Search and manage WordPress accounts")
                .font(.subheadline)
                .foregroundStyle(.secondary)
        case .ptaRoles:
            Text(vacancies == 0 ? "No open seats" : "\(vacancies) open seat\(vacancies == 1 ? "" : "s")")
                .font(.subheadline)
                .foregroundStyle(.secondary)
        case .backlog:
            Text(openTodos == 0 ? "No open items" : "\(openTodos) open item\(openTodos == 1 ? "" : "s")")
                .font(.subheadline)
                .foregroundStyle(.secondary)
        }
    }

    @ViewBuilder
    private func widgetLines(_ lines: [String], empty: String) -> some View {
        if lines.isEmpty {
            Text(empty).font(.subheadline).foregroundStyle(.secondary)
        } else {
            VStack(alignment: .leading, spacing: 4) {
                ForEach(Array(lines.enumerated()), id: \.offset) { _, line in
                    Text(line)
                        .font(.subheadline)
                        .lineLimit(1)
                }
            }
        }
    }

    @MainActor
    private func load() async {
        loading = true
        defer { loading = false }

        async let ordersTask = WooCommerceService.shared.recentOrders(perPage: 5)
        async let sheetsTask = WordPressService.shared.volunteerSheets()
        async let orgTask = WordPressService.shared.ptaRolesOrg()
        async let todosTask = WordPressService.shared.listTodos()

        if let loaded = try? await ordersTask { orders = loaded }
        if let loaded = try? await sheetsTask { sheets = loaded }
        if let org = try? await orgTask {
            vacancies = org.departments.reduce(0) { $0 + $1.roles.reduce(0) { $0 + $1.vacancy_count } }
        }
        if let todos = try? await todosTask {
            openTodos = todos.filter { !$0.completed }.count
        }

        await loadMemberships()
        await loadEvents()
    }

    @MainActor
    private func loadMemberships() async {
        do {
            memberships = try await WordPressService.shared.membershipSummary()
            membershipError = nil
        } catch {
            memberships = nil
            if case APIError.http(403, _) = error {
                membershipError = nil
            } else {
                membershipError = error.localizedDescription
            }
        }
    }

    @MainActor
    private func loadEvents() async {
        if let token = try? await auth.graphAccessToken() {
            let cal = Calendar.current
            let from = cal.startOfDay(for: Date())
            let to = cal.date(byAdding: .day, value: 14, to: from) ?? from
            if let ev = try? await GraphService.shared.sharedCalendarEvents(
                accessToken: token,
                calendars: AppConfig.sharedGraphCalendars,
                from: from,
                to: to
            ) {
                events = Array(ev.prefix(5))
            }
        }
    }
}

struct HomeWidgetCard<Content: View>: View {
    let id: HomeWidgetID
    @ViewBuilder var content: () -> Content

    var body: some View {
        Card {
            VStack(alignment: .leading, spacing: 10) {
                HStack(spacing: 10) {
                    Image(systemName: id.systemImage)
                        .font(.title3)
                        .foregroundStyle(id.accent)
                        .frame(width: 28)
                    VStack(alignment: .leading, spacing: 2) {
                        Text(id.title).font(.headline)
                        Text(id.subtitle)
                            .font(.caption)
                            .foregroundStyle(.secondary)
                    }
                    Spacer()
                    Image(systemName: "chevron.right")
                        .font(.caption.weight(.semibold))
                        .foregroundStyle(.tertiary)
                }
                content()
            }
        }
    }
}
