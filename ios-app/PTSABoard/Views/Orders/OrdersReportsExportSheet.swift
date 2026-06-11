import SwiftUI
import UniformTypeIdentifiers

struct OrdersReportsExportSheet: View {
    @Environment(\.dismiss) private var dismiss

    @State private var reports: [OrdersReportSummary] = []
    @State private var loading = false
    @State private var error: String?
    @State private var exportingId: Int?
    @State private var exportDocument: ReportExportDocument?
    @State private var showExporter = false
    @State private var exportFilename = "orders-report.xls"

    var body: some View {
        NavigationStack {
            Group {
                if loading && reports.isEmpty {
                    ProgressView("Loading reports…")
                        .frame(maxWidth: .infinity, maxHeight: .infinity)
                } else if reports.isEmpty {
                    EmptyStateView(
                        systemImage: "tablecells",
                        title: "No Saved Reports",
                        message: "Saved order reports from PTA Tools will appear here for one-tap export."
                    )
                } else {
                    List(reports) { report in
                        Button {
                            Task { await export(report) }
                        } label: {
                            ReportRow(report: report, exporting: exportingId == report.id)
                        }
                        .disabled(exportingId != nil)
                    }
                    .listStyle(.insetGrouped)
                }
            }
            .navigationTitle("Export Report")
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                ToolbarItem(placement: .cancellationAction) {
                    Button("Close") { dismiss() }
                }
            }
            .overlay(alignment: .top) {
                if let error {
                    ErrorBanner(message: error) { self.error = nil }
                        .padding(.top, 4)
                }
            }
            .fileExporter(
                isPresented: $showExporter,
                document: exportDocument,
                contentType: UTType(filenameExtension: "xls") ?? .data,
                defaultFilename: exportFilename
            ) { result in
                switch result {
                case .success:
                    dismiss()
                case .failure(let err):
                    self.error = err.localizedDescription
                }
            }
            .task { await load() }
        }
    }

    @MainActor
    private func load() async {
        loading = true
        defer { loading = false }
        do {
            reports = try await WordPressService.shared.listOrdersReports()
            error = nil
        } catch {
            self.error = error.localizedDescription
        }
    }

    @MainActor
    private func export(_ report: OrdersReportSummary) async {
        exportingId = report.id
        defer { exportingId = nil }
        do {
            let result = try await WordPressService.shared.exportOrdersReport(id: report.id)
            exportDocument = ReportExportDocument(data: result.data)
            exportFilename = result.filename
            showExporter = true
            error = nil
        } catch {
            self.error = error.localizedDescription
        }
    }
}

private struct ReportRow: View {
    let report: OrdersReportSummary
    let exporting: Bool

    var body: some View {
        HStack(spacing: 12) {
            Image(systemName: "tablecells.fill")
                .font(.title3)
                .foregroundStyle(.green)
                .frame(width: 28)

            VStack(alignment: .leading, spacing: 4) {
                Text(report.name)
                    .font(.headline)
                    .foregroundStyle(.primary)
                Text(subtitle)
                    .font(.caption)
                    .foregroundStyle(.secondary)
            }

            Spacer()

            if exporting {
                ProgressView()
            } else {
                Image(systemName: "square.and.arrow.down")
                    .foregroundStyle(.secondary)
            }
        }
        .padding(.vertical, 4)
    }

    private var subtitle: String {
        if let last = report.last_exported_at, !last.isEmpty {
            let rows = report.last_exported_rows > 0 ? " · \(report.last_exported_rows) rows" : ""
            return "Last exported \(prettyDate(last))\(rows)"
        }
        return "Not exported yet"
    }

    private func prettyDate(_ raw: String) -> String {
        let iso = ISO8601DateFormatter()
        iso.formatOptions = [.withInternetDateTime, .withFractionalSeconds]
        if let date = iso.date(from: raw) {
            return date.formatted(.relative(presentation: .named))
        }
        iso.formatOptions = [.withInternetDateTime]
        if let date = iso.date(from: raw + (raw.hasSuffix("Z") ? "" : "Z")) {
            return date.formatted(.relative(presentation: .named))
        }
        let mysql = DateFormatter()
        mysql.dateFormat = "yyyy-MM-dd HH:mm:ss"
        mysql.locale = Locale(identifier: "en_US_POSIX")
        mysql.timeZone = .current
        if let date = mysql.date(from: raw) {
            return date.formatted(.relative(presentation: .named))
        }
        return raw
    }
}
