import Foundation
import SwiftUI

/// Per-user Home widget visibility and order. Local first, then WordPress
/// usermeta so the same layout follows the board member across devices.
@MainActor
final class HomeWidgetStore: ObservableObject {

    @Published private(set) var prefs = HomeWidgetPrefs.defaults

    private var emailKey = ""
    private var persistTask: Task<Void, Never>?

    var visible: [HomeWidgetID] { prefs.visible }

    func bind(email: String) {
        let key = email.lowercased()
        guard key != emailKey else { return }
        emailKey = key
        prefs = loadLocal(for: key) ?? HomeWidgetPrefs.defaults
        guard !key.isEmpty else { return }
        Task { await refreshFromServer() }
    }

    func isVisible(_ id: HomeWidgetID) -> Bool {
        !prefs.hidden.contains(id)
    }

    func setVisible(_ id: HomeWidgetID, _ on: Bool) {
        var next = prefs
        if on {
            next.hidden.removeAll { $0 == id }
        } else if !next.hidden.contains(id) {
            next.hidden.append(id)
        }
        commit(next)
    }

    func move(from source: IndexSet, to destination: Int) {
        var order = prefs.order
        order.move(fromOffsets: source, toOffset: destination)
        var next = prefs
        next.order = order
        commit(next)
    }

    func reset() {
        commit(HomeWidgetPrefs.defaults)
    }

    private func commit(_ next: HomeWidgetPrefs) {
        var saved = next
        saved.updated = Int(Date().timeIntervalSince1970)
        prefs = saved
        saveLocal(saved, for: emailKey)
        persistTask?.cancel()
        persistTask = Task { await pushToServer(saved) }
    }

    private func defaultsKey(_ email: String) -> String {
        "home.widgets." + (email.isEmpty ? "guest" : email)
    }

    private func loadLocal(for email: String) -> HomeWidgetPrefs? {
        guard let data = UserDefaults.standard.data(forKey: defaultsKey(email)) else {
            return nil
        }
        return try? JSONDecoder().decode(HomeWidgetPrefs.self, from: data)
    }

    private func saveLocal(_ prefs: HomeWidgetPrefs, for email: String) {
        guard let data = try? JSONEncoder().encode(prefs) else { return }
        UserDefaults.standard.set(data, forKey: defaultsKey(email))
    }

    private func refreshFromServer() async {
        do {
            let remote = try await WordPressService.shared.fetchHomeWidgets()
            if remote.updated > 0 && remote.updated >= prefs.updated {
                prefs = remote
                saveLocal(remote, for: emailKey)
            }
        } catch {
            #if DEBUG
            print("[Home] widget sync failed: \(error.localizedDescription)")
            #endif
        }
    }

    private func pushToServer(_ prefs: HomeWidgetPrefs) async {
        guard !emailKey.isEmpty else { return }
        do {
            let saved = try await WordPressService.shared.saveHomeWidgets(prefs)
            self.prefs = saved
            saveLocal(saved, for: emailKey)
        } catch {
            #if DEBUG
            print("[Home] widget save failed: \(error.localizedDescription)")
            #endif
        }
    }
}
