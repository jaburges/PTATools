import SwiftUI

struct ProductsView: View {
    @State private var products: [WCProduct] = []
    @State private var loading = false
    @State private var error: String?
    @State private var search = ""
    @State private var typeFilter = "any"
    @State private var statusFilter = "any"
    @State private var showCreate = false

    private let types = [
        ("any", "All Types"),
        ("simple", "Simple"),
        ("variable", "Variable"),
        ("grouped", "Grouped"),
        ("external", "External"),
        ("auction", "Auction"),
        ("donation", "Donation")
    ]
    private let statuses = [
        ("any", "Any Status"),
        ("publish", "Published"),
        ("draft", "Drafts"),
        ("private", "Private"),
        ("pending", "Pending")
    ]

    var body: some View {
        Group {
            if products.isEmpty && !loading {
                EmptyStateView(
                    systemImage: "cube.box",
                    title: "No products",
                    message: error ?? "Pull to refresh, or try a different filter."
                )
            } else {
                List {
                    ForEach(products) { product in
                        NavigationLink(value: product) { ProductRow(product: product) }
                    }
                }
                .listStyle(.insetGrouped)
            }
        }
        .navigationTitle("Products")
        .searchable(text: $search, prompt: "Search products")
        .toolbar {
            ToolbarItem(placement: .topBarLeading) {
                Menu {
                    Picker("Type", selection: $typeFilter) {
                        ForEach(types, id: \.0) { Text($0.1).tag($0.0) }
                    }
                    Picker("Status", selection: $statusFilter) {
                        ForEach(statuses, id: \.0) { Text($0.1).tag($0.0) }
                    }
                } label: {
                    Image(systemName: "line.3.horizontal.decrease.circle")
                }
            }
            ToolbarItem(placement: .topBarTrailing) {
                Button { showCreate = true } label: {
                    Image(systemName: "plus.circle.fill")
                }
            }
        }
        .navigationDestination(for: WCProduct.self) { ProductEditView(product: $0) }
        .sheet(isPresented: $showCreate) {
            NavigationStack {
                ProductEditView(product: WCProduct.newDraft(), isCreating: true)
            }
        }
        .refreshable { await load() }
        .task(id: "\(typeFilter)-\(statusFilter)") { await load() }
        .onChange(of: search) {
            Task {
                try? await Task.sleep(nanoseconds: 350_000_000)
                if !Task.isCancelled { await load() }
            }
        }
        .overlay(alignment: .top) {
            if let error {
                ErrorBanner(message: error) { self.error = nil }.padding(.top, 4)
            }
        }
        .overlay {
            if loading && products.isEmpty { ProgressView().controlSize(.large) }
        }
    }

    @MainActor
    private func load() async {
        loading = true; defer { loading = false }
        do {
            products = try await WooCommerceService.shared.products(
                search: search.isEmpty ? nil : search,
                type: typeFilter == "any" ? nil : typeFilter,
                status: statusFilter == "any" ? nil : statusFilter
            )
            error = nil
        } catch {
            self.error = error.localizedDescription
        }
    }
}

extension WCProduct {
    static func newDraft() -> WCProduct {
        WCProduct(
            id: 0,
            name: "",
            type: "simple",
            status: "draft"
        )
    }
}

private struct ProductRow: View {
    let product: WCProduct

    var body: some View {
        HStack(alignment: .top, spacing: 12) {
            ThumbnailImage(url: product.primaryImageURL, size: 56)
            VStack(alignment: .leading, spacing: 4) {
                Text(product.name.isEmpty ? "(Untitled)" : product.name)
                    .font(.subheadline.weight(.semibold))
                    .lineLimit(2)
                HStack(spacing: 6) {
                    StatusPill(text: product.typeLabel, color: .accentColor)
                    if product.status != "publish" {
                        StatusPill(text: product.status.capitalized, color: .orange)
                    }
                    if product.on_sale == true {
                        StatusPill(text: "Sale", color: .pink)
                    }
                }
                HStack {
                    if let price = product.price, !price.isEmpty {
                        Text("$\(price)").font(.subheadline).monospacedDigit()
                    }
                    Spacer()
                    if let sku = product.sku, !sku.isEmpty {
                        Text(sku).font(.caption).foregroundStyle(.secondary)
                    }
                }
            }
        }
        .padding(.vertical, 4)
    }
}
