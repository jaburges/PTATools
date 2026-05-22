import Foundation

// MARK: - Orders

struct WCOrder: Codable, Identifiable, Hashable {
    let id: Int
    let number: String
    let status: String
    let currency: String
    let date_created: String?
    let date_modified: String?
    let total: String
    let customer_id: Int?
    let billing: WCAddress?
    let shipping: WCAddress?
    let line_items: [WCLineItem]
    let payment_method_title: String?
    let customer_note: String?

    var totalAmount: Double { Double(total) ?? 0 }

    var customerName: String {
        let b = billing
        let first = b?.first_name ?? ""
        let last = b?.last_name ?? ""
        let name = "\(first) \(last)".trimmingCharacters(in: .whitespaces)
        return name.isEmpty ? (b?.email ?? "Customer #\(customer_id ?? 0)") : name
    }

    var customerEmail: String? { billing?.email }

    var displayStatus: String {
        status.replacingOccurrences(of: "-", with: " ").capitalized
    }
}

struct WCAddress: Codable, Hashable {
    var first_name: String?
    var last_name: String?
    var address_1: String?
    var address_2: String?
    var city: String?
    var state: String?
    var postcode: String?
    var country: String?
    var email: String?
    var phone: String?
}

struct WCLineItem: Codable, Identifiable, Hashable {
    let id: Int
    let name: String
    let product_id: Int
    let variation_id: Int?
    let quantity: Int
    let total: String
    let sku: String?
}

// MARK: - Products

struct WCProduct: Codable, Identifiable, Hashable {
    var id: Int
    var name: String
    var slug: String? = nil
    var permalink: String? = nil
    var type: String           // simple, variable, grouped, external, auction, etc.
    var status: String         // publish, draft, pending, private
    var featured: Bool? = nil
    var description: String? = nil
    var short_description: String? = nil
    var sku: String? = nil
    var price: String? = nil
    var regular_price: String? = nil
    var sale_price: String? = nil
    var on_sale: Bool? = nil
    var manage_stock: Bool? = nil
    var stock_quantity: Int? = nil
    var stock_status: String? = nil
    var weight: String? = nil
    var dimensions: WCDimensions? = nil
    var shipping_required: Bool? = nil
    var shipping_taxable: Bool? = nil
    var shipping_class: String? = nil
    var tax_status: String? = nil   // taxable, shipping, none
    var tax_class: String? = nil
    var categories: [WCRef]? = nil
    var tags: [WCRef]? = nil
    var images: [WCImage]? = nil
    var attributes: [WCAttribute]? = nil
    var date_created: String? = nil
    var date_modified: String? = nil

    var primaryImageURL: URL? {
        guard let src = images?.first?.src else { return nil }
        return URL(string: src)
    }

    /// Friendly "type" label for the badge.
    var typeLabel: String {
        switch type.lowercased() {
        case "simple":   return "Simple"
        case "variable": return "Variable"
        case "grouped":  return "Grouped"
        case "external": return "External"
        case "auction":  return "Auction"
        case "donation": return "Donation"
        default:         return type.capitalized
        }
    }
}

struct WCDimensions: Codable, Hashable {
    var length: String? = nil
    var width: String? = nil
    var height: String? = nil
}

struct WCRef: Codable, Hashable, Identifiable {
    let id: Int
    let name: String
    let slug: String?
}

struct WCImage: Codable, Hashable, Identifiable {
    var id: Int?
    var src: String
    var name: String?
    var alt: String?
}

struct WCAttribute: Codable, Hashable, Identifiable {
    var id: Int
    var name: String
    var position: Int?
    var visible: Bool?
    var variation: Bool?
    var options: [String]
}
