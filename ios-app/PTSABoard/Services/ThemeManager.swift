import SwiftUI

@MainActor
final class ThemeManager: ObservableObject {

    enum AppearanceChoice: String, CaseIterable, Identifiable {
        case system, light, dark
        var id: String { rawValue }
        var label: String {
            switch self {
            case .system: return "System"
            case .light:  return "Light"
            case .dark:   return "Dark"
            }
        }
    }

    @AppStorage("ptsa.appearance") private var stored: String = AppearanceChoice.system.rawValue

    var choice: AppearanceChoice {
        get { AppearanceChoice(rawValue: stored) ?? .system }
        set { stored = newValue.rawValue; objectWillChange.send() }
    }

    var preferred: ColorScheme? {
        switch choice {
        case .system: return nil
        case .light:  return .light
        case .dark:   return .dark
        }
    }
}
