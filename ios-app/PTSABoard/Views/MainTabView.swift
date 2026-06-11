import SwiftUI

struct MainTabView: View {
    @EnvironmentObject var auth: AuthService
    @State private var selection: Tab = .orders
    @State private var showSettings = false
    @State private var showBacklog = false

    enum Tab: Hashable {
        case orders, products, calendar, users, ptaRoles
    }

    var body: some View {
        TabView(selection: $selection) {
            NavigationStack {
                OrdersView()
                    .toolbar { mainToolbar }
            }
            .tabItem { Label("Orders", systemImage: "bag.fill") }
            .tag(Tab.orders)

            NavigationStack {
                ProductsView()
                    .toolbar { mainToolbar }
            }
            .tabItem { Label("Products", systemImage: "cube.box.fill") }
            .tag(Tab.products)

            NavigationStack {
                CalendarView()
                    .toolbar { mainToolbar }
            }
            .tabItem { Label("Calendar", systemImage: "calendar") }
            .tag(Tab.calendar)

            NavigationStack {
                UsersView()
                    .toolbar { mainToolbar }
            }
            .tabItem { Label("Users", systemImage: "person.2.fill") }
            .tag(Tab.users)

            NavigationStack {
                PTARolesView()
                    .toolbar { mainToolbar }
            }
            .tabItem { Label("PTA Roles", systemImage: "person.3.sequence.fill") }
            .tag(Tab.ptaRoles)
        }
        .sheet(isPresented: $showSettings) {
            SettingsView()
                .environmentObject(auth)
        }
        .sheet(isPresented: $showBacklog) {
            NavigationStack {
                TodoView()
                    .environmentObject(auth)
            }
        }
    }

    @ToolbarContentBuilder
    private var mainToolbar: some ToolbarContent {
        ToolbarItem(placement: .topBarLeading) {
            Button {
                showBacklog = true
            } label: {
                Image(systemName: "list.clipboard.fill")
            }
            .accessibilityLabel("Tech backlog")
        }
        ToolbarItem(placement: .topBarTrailing) {
            Button {
                showSettings = true
            } label: {
                AvatarView(profile: auth.profile)
            }
            .accessibilityLabel("Account & settings")
        }
    }
}
