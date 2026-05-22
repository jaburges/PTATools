import SwiftUI

struct OrdersView: View {
    @State private var orders: [WCOrder] = []
    @State private var loading = false
    @State private var error: String?
    @State private var statusFilter = "any"
    @State private var search = ""

    private let statuses = [
        ("any", "All"),
        ("processing", "Processing"),
        ("on-hold", "On Hold"),
        ("pending", "Pending"),
        ("completed", "Completed"),
        ("cancelled", "Cancelled"),
        ("refunded", "Refunded")
    ]

    var body: some View {
        Group {
            if orders.isEmpty && !loading {
                EmptyStateView(
                    systemImage: "bag",
                    title: "No orders",
                    message: error ?? "Pull to refresh, or change the filter above."
                )
            } else {
                List {
                    ForEach(orders) { order in
                        NavigationLink(value: order) {
                            OrderRow(order: order)
                        }
                        .swipeActions(edge: .leading) {
                            Button {
                                Task { await update(order, to: "completed") }
                            } label: { Label("Complete", systemImage: "checkmark.circle.fill") }
                                .tint(.green)
                        }
                        .swipeActions(edge: .trailing) {
                            Button {
                                Task { await update(order, to: "on-hold") }
                            } label: { Label("Hold", systemImage: "pause.circle.fill") }
                                .tint(.orange)
                            Button(role: .destructive) {
                                Task { await update(order, to: "cancelled") }
                            } label: { Label("Cancel", systemImage: "xmark.circle.fill") }
                        }
                    }
                }
                .listStyle(.insetGrouped)
            }
        }
        .navigationTitle("Orders")
        .searchable(text: $search, prompt: "Search orders")
        .toolbar {
            ToolbarItem(placement: .topBarLeading) {
                Menu {
                    Picker("Status", selection: $statusFilter) {
                        ForEach(statuses, id: \.0) { Text($0.1).tag($0.0) }
                    }
                } label: {
                    Image(systemName: "line.3.horizontal.decrease.circle")
                }
            }
        }
        .navigationDestination(for: WCOrder.self) { OrderDetailView(order: $0) }
        .refreshable { await load() }
        .task(id: statusFilter) { await load() }
        .onChange(of: search) {
            Task {
                try? await Task.sleep(nanoseconds: 350_000_000)
                if !Task.isCancelled { await load() }
            }
        }
        .overlay(alignment: .top) {
            if let error {
                ErrorBanner(message: error) { self.error = nil }
                    .padding(.top, 4)
            }
        }
        .overlay {
            if loading && orders.isEmpty {
                ProgressView().controlSize(.large)
            }
        }
    }

    @MainActor
    private func load() async {
        loading = true; defer { loading = false }
        do {
            orders = try await WooCommerceService.shared.recentOrders(
                status: statusFilter == "any" ? nil : statusFilter,
                search: search.isEmpty ? nil : search
            )
            error = nil
        } catch {
            self.error = error.localizedDescription
        }
    }

    @MainActor
    private func update(_ order: WCOrder, to status: String) async {
        do {
            let updated = try await WooCommerceService.shared.updateOrderStatus(order.id, to: status)
            if let idx = orders.firstIndex(where: { $0.id == order.id }) {
                orders[idx] = updated
            }
        } catch {
            self.error = error.localizedDescription
        }
    }
}

private struct OrderRow: View {
    let order: WCOrder

    var body: some View {
        HStack(alignment: .top, spacing: 12) {
            ZStack {
                Circle().fill(order.statusColor.opacity(0.15))
                Image(systemName: "bag.fill").foregroundStyle(order.statusColor)
            }
            .frame(width: 38, height: 38)

            VStack(alignment: .leading, spacing: 4) {
                HStack {
                    Text("#\(order.number)")
                        .font(.headline)
                    Spacer()
                    Text(formatTotal(order))
                        .font(.headline)
                        .monospacedDigit()
                }
                Text(order.customerName)
                    .font(.subheadline)
                    .foregroundStyle(.secondary)
                    .lineLimit(1)
                HStack(spacing: 8) {
                    StatusPill(text: order.displayStatus, color: order.statusColor)
                    if order.line_items.count > 0 {
                        Text("\(order.line_items.reduce(0) { $0 + $1.quantity }) items")
                            .font(.caption)
                            .foregroundStyle(.secondary)
                    }
                    Spacer()
                    if let date = order.date_created {
                        Text(prettyDate(date))
                            .font(.caption)
                            .foregroundStyle(.secondary)
                    }
                }
            }
        }
        .padding(.vertical, 4)
    }

    private func formatTotal(_ o: WCOrder) -> String {
        let fmt = NumberFormatter()
        fmt.numberStyle = .currency
        fmt.currencyCode = o.currency
        return fmt.string(from: NSNumber(value: o.totalAmount)) ?? "\(o.currency) \(o.total)"
    }

    private func prettyDate(_ raw: String) -> String {
        let iso = ISO8601DateFormatter()
        iso.formatOptions = [.withInternetDateTime]
        if let d = iso.date(from: raw + (raw.hasSuffix("Z") ? "" : "Z")) {
            return d.formatted(.relative(presentation: .named))
        }
        // WP returns local datetime without TZ; try plain
        let fb = DateFormatter()
        fb.dateFormat = "yyyy-MM-dd'T'HH:mm:ss"
        fb.locale = Locale(identifier: "en_US_POSIX")
        if let d = fb.date(from: raw) {
            return d.formatted(.relative(presentation: .named))
        }
        return raw
    }
}
