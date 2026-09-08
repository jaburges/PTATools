import SwiftUI

struct HomeWidgetCustomizeView: View {
    @EnvironmentObject var widgets: HomeWidgetStore
    @Environment(\.dismiss) private var dismiss
    var showsDone = true

    var body: some View {
        List {
            Section {
                Text("Show the widgets you use. Drag to change the order. Your layout is saved to this phone and to your WordPress account.")
                    .font(.subheadline)
                    .foregroundStyle(.secondary)
            }
            Section("Widgets") {
                ForEach(widgets.prefs.order) { id in
                    Toggle(isOn: Binding(
                        get: { widgets.isVisible(id) },
                        set: { widgets.setVisible(id, $0) }
                    )) {
                        Label {
                            VStack(alignment: .leading, spacing: 2) {
                                Text(id.title)
                                Text(id.subtitle)
                                    .font(.caption)
                                    .foregroundStyle(.secondary)
                            }
                        } icon: {
                            Image(systemName: id.systemImage)
                                .foregroundStyle(id.accent)
                        }
                    }
                }
                .onMove { widgets.move(from: $0, to: $1) }
            }
            Section {
                Button("Reset to defaults") {
                    widgets.reset()
                }
            }
        }
        .environment(\.editMode, .constant(.active))
        .navigationTitle("Customize Home")
        .navigationBarTitleDisplayMode(.inline)
        .toolbar {
            if showsDone {
                ToolbarItem(placement: .topBarTrailing) {
                    Button("Done") { dismiss() }
                }
            }
        }
    }
}
