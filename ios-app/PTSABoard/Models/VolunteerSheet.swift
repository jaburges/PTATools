import Foundation

struct VolunteerSheetSummary: Decodable, Identifiable, Hashable {
    let id: Int
    let title: String
    let description: String?
    let event_date: String?
    let event_location: String?
    let status: String
    let activities: Int
    let spots_needed: Int
    let spots_filled: Int
    let spots_open: Int

    var fillLabel: String {
        "\(spots_filled)/\(spots_needed) filled"
    }

    var eventLabel: String {
        Self.displayDate(event_date)
    }

    static func displayDate(_ raw: String?) -> String {
        guard let raw, !raw.isEmpty else { return "" }
        let trimmed = String(raw.prefix(16)).replacingOccurrences(of: "T", with: " ")
        let inFmt = DateFormatter()
        inFmt.locale = Locale(identifier: "en_US_POSIX")
        inFmt.dateFormat = "yyyy-MM-dd HH:mm"
        guard let date = inFmt.date(from: trimmed) ?? inFmt.date(from: String(raw.prefix(10)) + " 00:00") else {
            return raw
        }
        let out = DateFormatter()
        out.dateStyle = .medium
        out.timeStyle = raw.count > 10 ? .short : .none
        return out.string(from: date)
    }
}

struct VolunteerSignup: Decodable, Identifiable, Hashable {
    let id: Int
    let user_id: Int
    let display_name: String
    let email: String?
    let signed_up_at: String?
}

struct VolunteerActivity: Decodable, Identifiable, Hashable {
    let id: Int
    let name: String
    let description: String?
    let slot_start: String?
    let slot_end: String?
    let time_label: String?
    let spots_needed: Int
    let spots_filled: Int
    let spots_open: Int
    let signups: [VolunteerSignup]

    var fillLabel: String { "\(spots_filled)/\(spots_needed)" }
}

struct VolunteerSheetDetail: Decodable, Identifiable, Hashable {
    let id: Int
    let title: String
    let description: String?
    let event_date: String?
    let event_location: String?
    let status: String
    let spots_needed: Int
    let spots_filled: Int
    let spots_open: Int
    let activities: [VolunteerActivity]

    var eventLabel: String { VolunteerSheetSummary.displayDate(event_date) }
    var fillLabel: String { "\(spots_filled)/\(spots_needed) filled" }
}
