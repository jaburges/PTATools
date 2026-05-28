import Foundation

// MARK: - Orders

struct WCOrder: Decodable, Identifiable, Hashable {
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

    enum CodingKeys: String, CodingKey {
        case id, number, status, currency, date_created, date_modified, total
        case customer_id, billing, shipping, line_items, items
        case payment_method_title, payment_method, customer_note
        case customer_email, customer_name
    }

    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        id = try c.decode(Int.self, forKey: .id)
        number = try c.decodeFlexibleStringIfPresent(forKey: .number) ?? "\(id)"
        status = try c.decodeIfPresent(String.self, forKey: .status) ?? ""
        currency = try c.decodeIfPresent(String.self, forKey: .currency) ?? ""
        date_created = try c.decodeIfPresent(String.self, forKey: .date_created)
        date_modified = try c.decodeIfPresent(String.self, forKey: .date_modified)
        total = try c.decodeFlexibleStringIfPresent(forKey: .total) ?? "0"
        customer_id = try c.decodeIfPresent(Int.self, forKey: .customer_id)
        shipping = try c.decodeIfPresent(WCAddress.self, forKey: .shipping)
        payment_method_title = try c.decodeIfPresent(String.self, forKey: .payment_method_title)
            ?? (try c.decodeIfPresent(String.self, forKey: .payment_method))
        customer_note = try c.decodeIfPresent(String.self, forKey: .customer_note)
        if let nativeBilling = try c.decodeIfPresent(WCAddress.self, forKey: .billing) {
            billing = nativeBilling
        } else {
            let displayName = try c.decodeIfPresent(String.self, forKey: .customer_name)
            let email = try c.decodeIfPresent(String.self, forKey: .customer_email)
            billing = WCAddress(displayName: displayName, email: email)
        }
        let nativeItems = try c.decodeIfPresent([WCLineItem].self, forKey: .line_items)
        let proxyItems = try c.decodeIfPresent([WCLineItem].self, forKey: .items)
        line_items = nativeItems ?? proxyItems ?? []
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

    init(
        first_name: String? = nil,
        last_name: String? = nil,
        address_1: String? = nil,
        address_2: String? = nil,
        city: String? = nil,
        state: String? = nil,
        postcode: String? = nil,
        country: String? = nil,
        email: String? = nil,
        phone: String? = nil
    ) {
        self.first_name = first_name
        self.last_name = last_name
        self.address_1 = address_1
        self.address_2 = address_2
        self.city = city
        self.state = state
        self.postcode = postcode
        self.country = country
        self.email = email
        self.phone = phone
    }

    init(displayName: String?, email: String?) {
        self.init(first_name: displayName, email: email)
    }
}

struct WCLineItem: Decodable, Identifiable, Hashable {
    let id: Int
    let name: String
    let product_id: Int
    let variation_id: Int?
    let quantity: Int
    let total: String
    let sku: String?

    enum CodingKeys: String, CodingKey {
        case id, name, product_id, variation_id, quantity, total, sku
    }

    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        id = try c.decode(Int.self, forKey: .id)
        name = try c.decodeIfPresent(String.self, forKey: .name) ?? ""
        product_id = try c.decodeIfPresent(Int.self, forKey: .product_id) ?? 0
        variation_id = try c.decodeIfPresent(Int.self, forKey: .variation_id)
        quantity = try c.decodeIfPresent(Int.self, forKey: .quantity) ?? 0
        total = try c.decodeFlexibleStringIfPresent(forKey: .total) ?? "0"
        sku = try c.decodeIfPresent(String.self, forKey: .sku)
    }
}

// MARK: - Products

struct WCProduct: Decodable, Identifiable, Hashable {
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
    var image: String? = nil
    var auction: AuctionSettings? = nil
    var attributes: [WCAttribute]? = nil
    var date_created: String? = nil
    var date_modified: String? = nil

    var primaryImageURL: URL? {
        if let src = images?.first?.src, let url = Self.normalizedImageURL(from: src) { return url }
        if let image, let url = Self.normalizedImageURL(from: image) { return url }
        return nil
    }

    private static func normalizedImageURL(from rawValue: String) -> URL? {
        let trimmed = rawValue.trimmingCharacters(in: .whitespacesAndNewlines)
        guard !trimmed.isEmpty else { return nil }

        let resolved: String
        if trimmed.hasPrefix("//") {
            resolved = "https:\(trimmed)"
        } else if trimmed.hasPrefix("/") {
            resolved = AppConfig.wordpressBaseURL.absoluteString + trimmed
        } else {
            resolved = trimmed
        }

        let encoded = resolved.addingPercentEncoding(withAllowedCharacters: .urlQueryAllowed) ?? resolved
        guard var components = URLComponents(string: encoded) else { return URL(string: encoded) }
        if components.scheme == nil {
            return URL(string: encoded, relativeTo: AppConfig.wordpressBaseURL)?.absoluteURL
        }
        if components.scheme == "http",
           components.host?.lowercased() == AppConfig.wordpressBaseURL.host?.lowercased() {
            components.scheme = "https"
        }
        return components.url
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

    init(
        id: Int,
        name: String,
        slug: String? = nil,
        permalink: String? = nil,
        type: String,
        status: String,
        featured: Bool? = nil,
        description: String? = nil,
        short_description: String? = nil,
        sku: String? = nil,
        price: String? = nil,
        regular_price: String? = nil,
        sale_price: String? = nil,
        on_sale: Bool? = nil,
        manage_stock: Bool? = nil,
        stock_quantity: Int? = nil,
        stock_status: String? = nil,
        weight: String? = nil,
        dimensions: WCDimensions? = nil,
        shipping_required: Bool? = nil,
        shipping_taxable: Bool? = nil,
        shipping_class: String? = nil,
        tax_status: String? = nil,
        tax_class: String? = nil,
        categories: [WCRef]? = nil,
        tags: [WCRef]? = nil,
        images: [WCImage]? = nil,
        image: String? = nil,
        auction: AuctionSettings? = nil,
        attributes: [WCAttribute]? = nil,
        date_created: String? = nil,
        date_modified: String? = nil
    ) {
        self.id = id
        self.name = name
        self.slug = slug
        self.permalink = permalink
        self.type = type
        self.status = status
        self.featured = featured
        self.description = description
        self.short_description = short_description
        self.sku = sku
        self.price = price
        self.regular_price = regular_price
        self.sale_price = sale_price
        self.on_sale = on_sale
        self.manage_stock = manage_stock
        self.stock_quantity = stock_quantity
        self.stock_status = stock_status
        self.weight = weight
        self.dimensions = dimensions
        self.shipping_required = shipping_required
        self.shipping_taxable = shipping_taxable
        self.shipping_class = shipping_class
        self.tax_status = tax_status
        self.tax_class = tax_class
        self.categories = categories
        self.tags = tags
        self.images = images
        self.image = image
        self.auction = auction
        self.attributes = attributes
        self.date_created = date_created
        self.date_modified = date_modified
    }

    enum CodingKeys: String, CodingKey {
        case id, name, slug, permalink, type, status, featured, description
        case short_description, sku, price, regular_price, sale_price, on_sale
        case manage_stock, stock_quantity, stock_status, weight, dimensions
        case shipping_required, shipping_taxable, shipping_class, tax_status
        case tax_class, categories, tags, images, image, attributes
        case auction, date_created, date_modified
    }

    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        id = try c.decode(Int.self, forKey: .id)
        name = try c.decodeIfPresent(String.self, forKey: .name) ?? ""
        slug = try c.decodeIfPresent(String.self, forKey: .slug)
        permalink = try c.decodeIfPresent(String.self, forKey: .permalink)
        type = try c.decodeIfPresent(String.self, forKey: .type) ?? "simple"
        status = try c.decodeIfPresent(String.self, forKey: .status) ?? "publish"
        featured = try c.decodeIfPresent(Bool.self, forKey: .featured)
        description = try c.decodeIfPresent(String.self, forKey: .description)
        short_description = try c.decodeIfPresent(String.self, forKey: .short_description)
        sku = try c.decodeIfPresent(String.self, forKey: .sku)
        price = try c.decodeFlexibleStringIfPresent(forKey: .price)
        regular_price = try c.decodeFlexibleStringIfPresent(forKey: .regular_price)
        sale_price = try c.decodeFlexibleStringIfPresent(forKey: .sale_price)
        on_sale = try c.decodeIfPresent(Bool.self, forKey: .on_sale)
        manage_stock = try c.decodeIfPresent(Bool.self, forKey: .manage_stock)
        stock_quantity = try c.decodeIfPresent(Int.self, forKey: .stock_quantity)
        stock_status = try c.decodeIfPresent(String.self, forKey: .stock_status)
        weight = try c.decodeFlexibleStringIfPresent(forKey: .weight)
        dimensions = try c.decodeIfPresent(WCDimensions.self, forKey: .dimensions)
        shipping_required = try c.decodeIfPresent(Bool.self, forKey: .shipping_required)
        shipping_taxable = try c.decodeIfPresent(Bool.self, forKey: .shipping_taxable)
        shipping_class = try c.decodeIfPresent(String.self, forKey: .shipping_class)
        tax_status = try c.decodeIfPresent(String.self, forKey: .tax_status)
        tax_class = try c.decodeIfPresent(String.self, forKey: .tax_class)
        categories = try c.decodeIfPresent([WCRef].self, forKey: .categories)
        tags = try c.decodeIfPresent([WCRef].self, forKey: .tags)
        if let nativeImages = try c.decodeFlexibleImagesIfPresent(forKey: .images), !nativeImages.isEmpty {
            images = nativeImages
        } else if let singleImage = try c.decodeFlexibleImageIfPresent(forKey: .image) {
            images = [singleImage]
        } else {
            images = nil
        }
        image = try c.decodeFlexibleImageIfPresent(forKey: .image)?.src
        auction = try c.decodeIfPresent(AuctionSettings.self, forKey: .auction)
        attributes = try c.decodeIfPresent([WCAttribute].self, forKey: .attributes)
        date_created = try c.decodeIfPresent(String.self, forKey: .date_created)
        date_modified = try c.decodeIfPresent(String.self, forKey: .date_modified)
    }
}

struct AuctionSettings: Codable, Hashable {
    var starting_bid: String?
    var bidding_end: String?
    var buy_it_now_enabled: Bool?
    var buy_it_now_price: String?
    var buy_it_now_pay_immediately: Bool?
    var status: String?
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

    init(id: Int?, src: String, name: String?, alt: String?) {
        self.id = id
        self.src = src
        self.name = name
        self.alt = alt
    }

    enum CodingKeys: String, CodingKey {
        case id, src, url, source_url, name, alt
    }

    init(from decoder: Decoder) throws {
        if let single = try? decoder.singleValueContainer(),
           let src = try? single.decode(String.self) {
            self.init(id: nil, src: src, name: nil, alt: nil)
            return
        }

        let c = try decoder.container(keyedBy: CodingKeys.self)
        let src = try c.decodeIfPresent(String.self, forKey: .src)
            ?? c.decodeIfPresent(String.self, forKey: .url)
            ?? c.decodeIfPresent(String.self, forKey: .source_url)
        guard let src, !src.isEmpty else {
            throw DecodingError.keyNotFound(
                CodingKeys.src,
                DecodingError.Context(codingPath: decoder.codingPath, debugDescription: "Expected product image src/url/source_url")
            )
        }
        self.init(
            id: try c.decodeIfPresent(Int.self, forKey: .id),
            src: src,
            name: try c.decodeIfPresent(String.self, forKey: .name),
            alt: try c.decodeIfPresent(String.self, forKey: .alt)
        )
    }

    func encode(to encoder: Encoder) throws {
        var c = encoder.container(keyedBy: CodingKeys.self)
        try c.encodeIfPresent(id, forKey: .id)
        try c.encode(src, forKey: .src)
        try c.encodeIfPresent(name, forKey: .name)
        try c.encodeIfPresent(alt, forKey: .alt)
    }
}

struct WCAttribute: Codable, Hashable, Identifiable {
    var id: Int
    var name: String
    var position: Int?
    var visible: Bool?
    var variation: Bool?
    var options: [String]
}

private extension KeyedDecodingContainer {
    func decodeFlexibleStringIfPresent(forKey key: Key) throws -> String? {
        if let value = try? decodeIfPresent(String.self, forKey: key) {
            return value
        }
        if let value = try? decodeIfPresent(Double.self, forKey: key) {
            return String(value)
        }
        if let value = try? decodeIfPresent(Int.self, forKey: key) {
            return String(value)
        }
        return nil
    }

    func decodeFlexibleImageIfPresent(forKey key: Key) throws -> WCImage? {
        if let image = try? decodeIfPresent(WCImage.self, forKey: key) {
            return image
        }
        if let src = try? decodeIfPresent(String.self, forKey: key), !src.isEmpty {
            return WCImage(id: nil, src: src, name: nil, alt: nil)
        }
        return nil
    }

    func decodeFlexibleImagesIfPresent(forKey key: Key) throws -> [WCImage]? {
        if let images = try? decodeIfPresent([WCImage].self, forKey: key) {
            return images
        }
        if let strings = try? decodeIfPresent([String].self, forKey: key) {
            return strings
                .filter { !$0.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty }
                .map { WCImage(id: nil, src: $0, name: nil, alt: nil) }
        }
        if let image = try decodeFlexibleImageIfPresent(forKey: key) {
            return [image]
        }
        return nil
    }
}
