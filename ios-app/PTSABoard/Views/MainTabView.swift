import SwiftUI

struct MainTabView: View {
    @EnvironmentObject var auth: AuthService
    @State private var selection: Tab = .orders
    @State private var showSettings = false

    enum Tab: Hashable {
        case orders, products, calendar, users, todo
    }

    var body: some View {
        TabView(selection: $selection) {
            NavigationStack {
                OrdersView()
                    .toolbar { avatarToolbar }
            }
            .tabItem { Label("Orders", systemImage: "bag.fill") }
            .tag(Tab.orders)

            NavigationStack {
                ProductsView()
                    .toolbar { avatarToolbar }
            }
            .tabItem { Label("Products", systemImage: "cube.box.fill") }
            .tag(Tab.products)

            NavigationStack {
                CalendarView()
                    .toolbar { avatarToolbar }
            }
            .tabItem { Label("Calendar", systemImage: "calendar") }
            .tag(Tab.calendar)

            NavigationStack {
                UsersView()
                    .toolbar { avatarToolbar }
            }
            .tabItem { Label("Users", systemImage: "person.2.fill") }
            .tag(Tab.users)

            NavigationStack {
                TodoView()
                    .toolbar { avatarToolbar }
            }
            .tabItem { Label("Backlog", systemImage: "checklist") }
            .tag(Tab.todo)
        }
        .sheet(isPresented: $showSettings) {
            SettingsView()
                .environmentObject(auth)
        }
    }

    @ToolbarContentBuilder
    private var avatarToolbar: some ToolbarContent {
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
