import Foundation
import SwiftUI
import UniformTypeIdentifiers

struct PTSAMe: Decodable {
    let id: Int
    let email: String
    let display_name: String
    let roles: [String]
}

struct OrdersReportSummary: Decodable, Identifiable {
    let id: Int
    let name: String
    let modified: String?
    let last_exported_at: String?
    let last_exported_rows: Int
}

struct ReportExportDocument: FileDocument {
    static var readableContentTypes: [UTType] {
        [UTType(filenameExtension: "xls") ?? .data]
    }

    var data: Data

    init(data: Data) {
        self.data = data
    }

    init(configuration: ReadConfiguration) throws {
        data = configuration.file.regularFileContents ?? Data()
    }

    func fileWrapper(configuration: WriteConfiguration) throws -> FileWrapper {
        FileWrapper(regularFileWithContents: data)
    }
}
