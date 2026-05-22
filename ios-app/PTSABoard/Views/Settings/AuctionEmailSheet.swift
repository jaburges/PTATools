import SwiftUI

struct AuctionEmailSheet: View {
    @EnvironmentObject var auth: AuthService
    @Environment(\.dismiss) private var dismiss

    @State private var toAddresses: String = ""
    @State private var subject: String = "Wilder PTSA Auction — Items List"
    @State private var bodyNote: String = "Here's the current list of items in the Wilder PTSA Auction. Tap any item to view or bid."
    @State private var working = false
    @State private var error: String?
    @State private var info: String?
    @State private var useGraphMail = true

    var body: some View {
        NavigationStack {
            Form {
                Section("Recipients") {
                    TextField("comma,separated@wilderptsa.net", text: $toAddresses, axis: .vertical)
                        .keyboardType(.emailAddress)
                        .autocorrectionDisabled(true)
                        .textInputAutocapitalization(.never)
                        .lineLimit(2...4)
                    if let me = auth.profile {
                        Button {
                            appendEmail(me.email)
                        } label: { Label("Add me", systemImage: "plus.circle") }
                    }
                    Button {
                        appendEmail("board@\(AppConfig.allowedEmailDomain)")
                    } label: { Label("Add board@wilderptsa.net", systemImage: "person.3.fill") }
                }

                Section("Email") {
                    TextField("Subject", text: $subject)
                    TextField("Note", text: $bodyNote, axis: .vertical).lineLimit(3...8)
                }

                Section {
                    Toggle("Send via my mailbox", isOn: $useGraphMail)
                } footer: {
                    Text(useGraphMail
                         ? "Sends from your Microsoft mailbox (you'll be the From address)."
                         : "Sends via the website's mailer with the auction-items template.")
                        .font(.caption)
                }
            }
            .navigationTitle("Auction email")
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                ToolbarItem(placement: .cancellationAction) { Button("Cancel") { dismiss() } }
                ToolbarItem(placement: .confirmationAction) {
                    Button("Send") { Task { await send() } }
                        .disabled(toAddresses.trimmingCharacters(in: .whitespaces).isEmpty || working)
                }
            }
            .overlay { if working { ProgressView().controlSize(.large) } }
            .overlay(alignment: .top) {
                VStack {
                    if let error { ErrorBanner(message: error) { self.error = nil } }
                    if let info {
                        Text(info)
                            .font(.footnote)
                            .padding(10)
                            .background(.green.opacity(0.9))
                            .foregroundStyle(.white)
                            .clipShape(RoundedRectangle(cornerRadius: 10, style: .continuous))
                            .padding(.horizontal)
                    }
                }
            }
        }
    }

    private func appendEmail(_ e: String) {
        let trimmed = toAddresses.trimmingCharacters(in: .whitespacesAndNewlines)
        if trimmed.isEmpty { toAddresses = e }
        else if !trimmed.contains(e) { toAddresses = trimmed + ", " + e }
    }

    @MainActor
    private func send() async {
        let recipients = toAddresses
            .split(whereSeparator: { ",;\n ".contains($0) })
            .map { String($0).trimmingCharacters(in: .whitespacesAndNewlines) }
            .filter { !$0.isEmpty }
        guard !recipients.isEmpty else { return }
        working = true; defer { working = false }

        do {
            if useGraphMail {
                let token = try await auth.graphAccessToken()
                let html = """
                <p>\(bodyNote)</p>
                <p><a href="https://wilderptsa.net/selling/auction/">View all auction items →</a></p>
                <p style="color:#777;font-size:12px;">Sent from PTSA Board iOS app.</p>
                """
                try await GraphService.shared.sendMail(
                    accessToken: token, subject: subject, bodyHTML: html, to: recipients
                )
            } else {
                try await WordPressService.shared.sendAuctionItemsEmail(to: recipients, subject: subject)
            }
            info = "Sent to \(recipients.count) recipient\(recipients.count == 1 ? "" : "s")."
            try? await Task.sleep(nanoseconds: 1_500_000_000)
            dismiss()
        } catch {
            self.error = error.localizedDescription
        }
    }
}
