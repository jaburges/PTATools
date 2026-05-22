import SwiftUI
import PhotosUI

struct ProductEditView: View {
    @State var product: WCProduct
    var isCreating: Bool = false

    @State private var saving = false
    @State private var error: String?
    @State private var showAdvanced = false
    @State private var photoPickerItem: PhotosPickerItem?
    @State private var showCameraPicker = false

    @Environment(\.dismiss) private var dismiss

    var body: some View {
        Form {
            // Image section
            Section {
                HStack(spacing: 12) {
                    ThumbnailImage(url: product.primaryImageURL, size: 84, corner: 14)
                    VStack(alignment: .leading, spacing: 8) {
                        PhotosPicker(
                            selection: $photoPickerItem, matching: .images
                        ) {
                            Label("Choose from Library", systemImage: "photo.on.rectangle")
                        }
                        .buttonStyle(.bordered)

                        Button {
                            showCameraPicker = true
                        } label: {
                            Label("Take Photo", systemImage: "camera.fill")
                        }
                        .buttonStyle(.bordered)
                    }
                    Spacer()
                }
            } header: { Text("Image") }

            // Always-visible basics
            Section {
                TextField("Name", text: $product.name)
                TextField("Short description", text: Binding(
                    get: { product.short_description ?? "" },
                    set: { product.short_description = $0 }
                ), axis: .vertical).lineLimit(2...5)

                TextField("Description", text: Binding(
                    get: { product.description ?? "" },
                    set: { product.description = $0 }
                ), axis: .vertical).lineLimit(3...10)

                Picker("Type", selection: $product.type) {
                    Text("Simple").tag("simple")
                    Text("Variable").tag("variable")
                    Text("Grouped").tag("grouped")
                    Text("External").tag("external")
                    Text("Auction").tag("auction")
                    Text("Donation").tag("donation")
                }
            } header: { Text("Basics") }

            // Price (hidden for grouped / external; donation gets a different label)
            if pricingVisible {
                Section {
                    HStack {
                        Text("$")
                        TextField("0.00", text: Binding(
                            get: { product.regular_price ?? product.price ?? "" },
                            set: { product.regular_price = $0; product.price = $0 }
                        ))
                        .keyboardType(.decimalPad)
                    }
                    HStack {
                        Text("Sale $")
                        TextField("Optional", text: Binding(
                            get: { product.sale_price ?? "" },
                            set: { product.sale_price = $0 }
                        ))
                        .keyboardType(.decimalPad)
                    }
                } header: { Text(product.type == "donation" ? "Suggested Amount" : "Price") }
            }

            Section {
                Picker("Status", selection: $product.status) {
                    Text("Published").tag("publish")
                    Text("Draft").tag("draft")
                    Text("Pending").tag("pending")
                    Text("Private").tag("private")
                }
                Toggle("Featured", isOn: Binding(
                    get: { product.featured ?? false },
                    set: { product.featured = $0 }
                ))
            } header: { Text("Visibility") }

            // Inventory (hide for external, grouped, donation, auction)
            if inventoryVisible {
                Section {
                    TextField("SKU", text: Binding(
                        get: { product.sku ?? "" },
                        set: { product.sku = $0 }
                    ))
                    Toggle("Manage stock", isOn: Binding(
                        get: { product.manage_stock ?? false },
                        set: { product.manage_stock = $0 }
                    ))
                    if product.manage_stock == true {
                        HStack {
                            Text("Quantity")
                            Spacer()
                            TextField("0", value: Binding(
                                get: { product.stock_quantity ?? 0 },
                                set: { product.stock_quantity = $0 }
                            ), format: .number)
                                .keyboardType(.numberPad)
                                .multilineTextAlignment(.trailing)
                        }
                    }
                } header: { Text("Inventory") }
            }

            // Advanced (collapsed by default, customized per type)
            Section {
                DisclosureGroup(isExpanded: $showAdvanced) {
                    if shippingVisible {
                        TextField("Weight (lb)", text: Binding(
                            get: { product.weight ?? "" },
                            set: { product.weight = $0 }
                        )).keyboardType(.decimalPad)
                        TextField("Length", text: Binding(
                            get: { product.dimensions?.length ?? "" },
                            set: { val in
                                var d = product.dimensions ?? WCDimensions()
                                d.length = val
                                product.dimensions = d
                            }
                        )).keyboardType(.decimalPad)
                        TextField("Width", text: Binding(
                            get: { product.dimensions?.width ?? "" },
                            set: { val in
                                var d = product.dimensions ?? WCDimensions()
                                d.width = val
                                product.dimensions = d
                            }
                        )).keyboardType(.decimalPad)
                        TextField("Height", text: Binding(
                            get: { product.dimensions?.height ?? "" },
                            set: { val in
                                var d = product.dimensions ?? WCDimensions()
                                d.height = val
                                product.dimensions = d
                            }
                        )).keyboardType(.decimalPad)
                        TextField("Shipping class", text: Binding(
                            get: { product.shipping_class ?? "" },
                            set: { product.shipping_class = $0 }
                        ))
                    }
                    if taxVisible {
                        Picker("Tax status", selection: Binding(
                            get: { product.tax_status ?? "taxable" },
                            set: { product.tax_status = $0 }
                        )) {
                            Text("Taxable").tag("taxable")
                            Text("Shipping only").tag("shipping")
                            Text("None").tag("none")
                        }
                        TextField("Tax class", text: Binding(
                            get: { product.tax_class ?? "" },
                            set: { product.tax_class = $0 }
                        ))
                    }
                } label: {
                    Label("Advanced", systemImage: "slider.horizontal.3")
                }
            } footer: {
                if !showAdvanced {
                    Text("Tax, shipping and dimensions are hidden by default — tap Advanced to edit.")
                }
            }
        }
        .navigationTitle(isCreating ? "New Product" : product.name.isEmpty ? "Product" : product.name)
        .navigationBarTitleDisplayMode(.inline)
        .toolbar {
            ToolbarItem(placement: .topBarTrailing) {
                Button(isCreating ? "Create" : "Save") {
                    Task { await save() }
                }
                .disabled(saving || product.name.isEmpty)
            }
            ToolbarItem(placement: .topBarLeading) {
                if isCreating {
                    Button("Cancel") { dismiss() }
                }
            }
        }
        .overlay {
            if saving { ProgressView().controlSize(.large) }
        }
        .overlay(alignment: .top) {
            if let error {
                ErrorBanner(message: error) { self.error = nil }.padding(.top, 4)
            }
        }
        .onChange(of: photoPickerItem) {
            Task { await loadPickedPhoto() }
        }
        .sheet(isPresented: $showCameraPicker) {
            CameraImagePicker { image in
                Task { await upload(image: image) }
            }
        }
    }

    private var pricingVisible: Bool {
        !["grouped"].contains(product.type)
    }
    private var inventoryVisible: Bool {
        !["external", "grouped", "donation", "auction"].contains(product.type)
    }
    private var shippingVisible: Bool {
        !["external", "donation"].contains(product.type)
    }
    private var taxVisible: Bool { true }

    @MainActor
    private func save() async {
        saving = true; defer { saving = false }
        do {
            var patch: [String: Any] = [:]
            patch["name"]              = product.name
            patch["type"]              = product.type
            patch["status"]            = product.status
            patch["regular_price"]     = product.regular_price ?? ""
            patch["sale_price"]        = product.sale_price ?? ""
            patch["description"]       = product.description ?? ""
            patch["short_description"] = product.short_description ?? ""
            patch["sku"]               = product.sku ?? ""
            patch["manage_stock"]      = product.manage_stock ?? false
            if product.manage_stock == true, let q = product.stock_quantity {
                patch["stock_quantity"] = q
            }
            patch["featured"]          = product.featured ?? false
            patch["weight"]            = product.weight ?? ""
            patch["dimensions"]        = [
                "length": product.dimensions?.length ?? "",
                "width":  product.dimensions?.width ?? "",
                "height": product.dimensions?.height ?? ""
            ]
            patch["shipping_class"]    = product.shipping_class ?? ""
            patch["tax_status"]        = product.tax_status ?? "taxable"
            patch["tax_class"]         = product.tax_class ?? ""

            if let images = product.images, !images.isEmpty {
                patch["images"] = images.map { ["src": $0.src] }
            }

            if isCreating {
                let created = try await WooCommerceService.shared.createProduct(patch)
                product = created
                dismiss()
            } else {
                let updated = try await WooCommerceService.shared.updateProduct(product.id, patch: patch)
                product = updated
            }
        } catch {
            self.error = error.localizedDescription
        }
    }

    @MainActor
    private func loadPickedPhoto() async {
        guard let item = photoPickerItem else { return }
        do {
            if let data = try await item.loadTransferable(type: Data.self),
               let img = UIImage(data: data) {
                await upload(image: img)
            }
        } catch {
            self.error = error.localizedDescription
        }
    }

    @MainActor
    private func upload(image: UIImage) async {
        saving = true; defer { saving = false }
        do {
            let uploaded = try await WooCommerceService.shared.uploadImage(image)
            if product.images == nil { product.images = [] }
            product.images?.insert(uploaded, at: 0)
        } catch {
            self.error = "Image upload failed: \(error.localizedDescription)"
        }
    }
}

// MARK: - Camera picker

struct CameraImagePicker: UIViewControllerRepresentable {
    let onImage: (UIImage) -> Void

    func makeCoordinator() -> Coord { Coord(onImage: onImage) }

    func makeUIViewController(context: Context) -> UIImagePickerController {
        let p = UIImagePickerController()
        p.sourceType = UIImagePickerController.isSourceTypeAvailable(.camera) ? .camera : .photoLibrary
        p.delegate = context.coordinator
        return p
    }

    func updateUIViewController(_ uiViewController: UIImagePickerController, context: Context) {}

    final class Coord: NSObject, UIImagePickerControllerDelegate, UINavigationControllerDelegate {
        let onImage: (UIImage) -> Void
        init(onImage: @escaping (UIImage) -> Void) { self.onImage = onImage }

        func imagePickerController(
            _ picker: UIImagePickerController,
            didFinishPickingMediaWithInfo info: [UIImagePickerController.InfoKey: Any]
        ) {
            if let img = info[.originalImage] as? UIImage { onImage(img) }
            picker.dismiss(animated: true)
        }
        func imagePickerControllerDidCancel(_ picker: UIImagePickerController) {
            picker.dismiss(animated: true)
        }
    }
}
