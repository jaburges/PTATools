import SwiftUI

struct OrderDetailView: View {
    @State var order: WCOrder
    @State private var error: String?
    @State private var processing = false
    @State private var showRefund = false
    @State private var refundAmount: String = ""
    @State private var refundReason: String = ""
    @State private var showNote = false
    @State private var noteText = ""
    @State private var customerVisible = false

    var body: some View {
        ScrollView {
            VStack(spacing: 16) {
                summary
                customerCard
                itemsCard
                actionsCard
                if order.customer_note?.isEmpty == false {
                    Card { Text(order.customer_note!) }
                }
            }
            .padding()
        }
        .navigationTitle("Order #\(order.number)")
        .navigationBarTitleDisplayMode(.inline)
        .overlay(alignment: .top) {
            if let error {
                ErrorBanner(message: error) { self.error = nil }
                    .padding(.top, 8)
            }
        }
        .sheet(isPresented: $showRefund) {
            refundSheet.presentationDetents([.medium])
        }
        .sheet(isPresented: $showNote) {
            noteSheet.presentationDetents([.medium])
        }
    }

    // MARK: - Cards

    private var summary: some View {
        Card {
            HStack(alignment: .top) {
                VStack(alignment: .leading, spacing: 6) {
                    StatusPill(text: order.displayStatus, color: order.statusColor)
                    Text(formatTotal(order))
                        .font(.system(size: 32, weight: .bold, design: .rounded))
                        .monospacedDigit()
                    if let pm = order.payment_method_title, !pm.isEmpty {
                        Label(pm, systemImage: "creditcard.fill")
                            .font(.subheadline)
                            .foregroundStyle(.secondary)
                    }
                }
                Spacer()
            }
        }
    }

    private var customerCard: some View {
        Card {
            VStack(alignment: .leading, spacing: 8) {
                Text("Customer").font(.caption).foregroundStyle(.secondary)
                Text(order.customerName).font(.headline)
                if let e = order.customerEmail, !e.isEmpty {
                    Link(destination: URL(string: "mailto:\(e)")!) {
                        Label(e, systemImage: "envelope")
                    }
                    .font(.subheadline)
                }
                if let p = order.billing?.phone, !p.isEmpty {
                    Link(destination: URL(string: "tel:\(p.filter { !$0.isWhitespace })")!) {
                        Label(p, systemImage: "phone")
                    }
                    .font(.subheadline)
                }
            }
        }
    }

    private var itemsCard: some View {
        Card {
            VStack(alignment: .leading, spacing: 10) {
                Text("Items").font(.caption).foregroundStyle(.secondary)
                ForEach(order.line_items) { item in
                    HStack(alignment: .top) {
                        Text("\(item.quantity)×")
                            .font(.subheadline.weight(.semibold))
                            .frame(width: 32, alignment: .leading)
                        VStack(alignment: .leading, spacing: 2) {
                            Text(item.name).font(.subheadline)
                            if let sku = item.sku, !sku.isEmpty {
                                Text(sku).font(.caption).foregroundStyle(.secondary)
                            }
                        }
                        Spacer()
                        Text(item.total)
                            .font(.subheadline).monospacedDigit()
                        Menu {
                            // Always-available row actions
                            Button {
                                if let mail = order.customerEmail {
                                    let subject = "Your order for \(item.name)".addingPercentEncoding(withAllowedCharacters: .urlQueryAllowed) ?? ""
                                    UIApplication.shared.open(URL(string: "mailto:\(mail)?subject=\(subject)")!)
                                }
                            } label: { Label("Email customer about this item", systemImage: "envelope") }

                            // Context-specific (heuristic on item name) — auction items get a "pickup info" template
                            if item.name.localizedCaseInsensitiveContains("auction") {
                                Button {
                                    sendAuctionPickup(for: item)
                                } label: { Label("Send auction pickup info", systemImage: "tag.fill") }
                            }
                            if item.name.localizedCaseInsensitiveContains("ticket") {
                                Button {
                                    if let mail = order.customerEmail {
                                        let subj = "Your tickets for \(item.name)".addingPercentEncoding(withAllowedCharacters: .urlQueryAllowed) ?? ""
                                        UIApplication.shared.open(URL(string: "mailto:\(mail)?subject=\(subj)")!)
                                    }
                                } label: { Label("Resend tickets / event info", systemImage: "ticket.fill") }
                            }
                            if item.name.localizedCaseInsensitiveContains("donation") {
                                Button {
                                    if let mail = order.customerEmail {
                                        let subj = "Thank you for your donation".addingPercentEncoding(withAllowedCharacters: .urlQueryAllowed) ?? ""
                                        UIApplication.shared.open(URL(string: "mailto:\(mail)?subject=\(subj)")!)
                                    }
                                } label: { Label("Send tax-receipt", systemImage: "doc.text.fill") }
                            }
                        } label: {
                            Image(systemName: "ellipsis.circle")
                                .foregroundStyle(Color.accentColor)
                        }
                    }
                    Divider()
                }
            }
        }
    }

    private func sendAuctionPickup(for item: WCLineItem) {
        guard let mail = order.customerEmail else { return }
        let subj = "Your auction win: \(item.name)"
            .addingPercentEncoding(withAllowedCharacters: .urlQueryAllowed) ?? ""
        let body = """
        Hi \(order.customerName),

        Congratulations on winning "\(item.name)" in the Wilder PTSA Auction! Here's how to pick it up:

        — When: please see the auction info page.
        — Where: Wilder PTSA office.
        — Need help? Just reply to this email.

        Thank you for supporting Wilder!
        """
            .addingPercentEncoding(withAllowedCharacters: .urlQueryAllowed) ?? ""
        UIApplication.shared.open(URL(string: "mailto:\(mail)?subject=\(subj)&body=\(body)")!)
    }

    private var actionsCard: some View {
        Card {
            VStack(alignment: .leading, spacing: 10) {
                Text("Actions").font(.caption).foregroundStyle(.secondary)
                actionRow(
                    "Mark as Completed", "checkmark.seal.fill", .green,
                    enabled: order.status != "completed"
                ) { Task { await update(to: "completed") } }
                actionRow(
                    "Mark as Processing", "shippingbox.fill", .blue,
                    enabled: order.status != "processing"
                ) { Task { await update(to: "processing") } }
                actionRow(
                    "Put On Hold", "pause.circle.fill", .orange,
                    enabled: order.status != "on-hold"
                ) { Task { await update(to: "on-hold") } }
                actionRow(
                    "Cancel Order", "xmark.octagon.fill", .red,
                    enabled: order.status != "cancelled"
                ) { Task { await update(to: "cancelled") } }
                Divider()
                actionRow("Refund…", "arrow.uturn.backward.circle.fill", .purple) {
                    refundAmount = String(format: "%.2f", order.totalAmount)
                    showRefund = true
                }
                actionRow("Add Note…", "note.text.badge.plus", .indigo) {
                    showNote = true
                }
                actionRow("Email Customer…", "envelope.badge.fill", .teal) {
                    if let mail = order.customerEmail {
                        UIApplication.shared.open(URL(string: "mailto:\(mail)?subject=Your%20PTSA%20Order%20%23\(order.number)")!)
                    }
                }
            }
        }
        .overlay(alignment: .topTrailing) {
            if processing { ProgressView().padding(10) }
        }
    }

    private func actionRow(_ title: String, _ icon: String, _ color: Color, enabled: Bool = true, _ run: @escaping () -> Void) -> some View {
        Button(action: run) {
            HStack {
                Image(systemName: icon).foregroundStyle(color).frame(width: 24)
                Text(title).foregroundStyle(enabled ? .primary : .secondary)
                Spacer()
                Image(systemName: "chevron.right").foregroundStyle(.tertiary).font(.footnote)
            }
            .padding(.vertical, 6)
        }
        .disabled(!enabled || processing)
    }

    // MARK: - Sheets

    private var refundSheet: some View {
        NavigationStack {
            Form {
                Section("Amount") {
                    HStack {
                        Text(order.currency)
                        TextField("0.00", text: $refundAmount)
                            .keyboardType(.decimalPad)
                    }
                }
                Section("Reason") {
                    TextField("Optional", text: $refundReason, axis: .vertical)
                }
            }
            .navigationTitle("Issue refund")
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                ToolbarItem(placement: .cancellationAction) {
                    Button("Cancel") { showRefund = false }
                }
                ToolbarItem(placement: .confirmationAction) {
                    Button("Refund") {
                        Task {
                            showRefund = false
                            await runRefund()
                        }
                    }.disabled(Double(refundAmount) == nil)
                }
            }
        }
    }

    private var noteSheet: some View {
        NavigationStack {
            Form {
                TextField("Note", text: $noteText, axis: .vertical).lineLimit(3...10)
                Toggle("Send to customer", isOn: $customerVisible)
            }
            .navigationTitle("Add note")
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                ToolbarItem(placement: .cancellationAction) { Button("Cancel") { showNote = false } }
                ToolbarItem(placement: .confirmationAction) {
                    Button("Add") {
                        Task { showNote = false; await addNote() }
                    }.disabled(noteText.isEmpty)
                }
            }
        }
    }

    // MARK: - Actions

    @MainActor
    private func update(to status: String) async {
        processing = true; defer { processing = false }
        do {
            order = try await WooCommerceService.shared.updateOrderStatus(order.id, to: status)
        } catch { self.error = error.localizedDescription }
    }

    @MainActor
    private func runRefund() async {
        processing = true; defer { processing = false }
        guard let amount = Double(refundAmount) else { return }
        do {
            try await WooCommerceService.shared.refundOrder(order.id, amount: amount, reason: refundReason.isEmpty ? nil : refundReason)
            order = try await WooCommerceService.shared.fetchOrder(order.id)
        } catch { self.error = error.localizedDescription }
    }

    @MainActor
    private func addNote() async {
        processing = true; defer { processing = false }
        do {
            try await WooCommerceService.shared.addOrderNote(order.id, note: noteText, customerVisible: customerVisible)
            noteText = ""
        } catch { self.error = error.localizedDescription }
    }

    private func formatTotal(_ o: WCOrder) -> String {
        let fmt = NumberFormatter()
        fmt.numberStyle = .currency
        fmt.currencyCode = o.currency
        return fmt.string(from: NSNumber(value: o.totalAmount)) ?? "\(o.currency) \(o.total)"
    }
}
