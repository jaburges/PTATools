import Foundation

/// Centralized configuration for the PTSA Board app.
///
/// Before shipping you MUST fill in the values below from your tenant /
/// WordPress site. Most of these are intentionally NOT hardcoded as secrets
/// in source — see `README.md` for how to wire them via a build-time
/// `Config.xcconfig`, environment variables, or by editing this file
/// directly during initial setup.
enum AppConfig {

    // MARK: - Microsoft Entra ID (Azure AD)

    /// The Application (client) ID of the iOS app registration in Entra ID.
    /// App: "Wilder PTSA Board (iOS)" — single-tenant, public client,
    /// redirect URI msauth.net.wilderptsa.PTSABoard://auth, admin consent
    /// granted for User.Read, User.ReadBasic.All, Calendars.ReadWrite.Shared,
    /// Mail.Send, openid, profile, offline_access.
    static let entraClientId: String = "62d983db-f1e9-49cf-a833-b332ea3af84e"

    /// The tenant ID for the wilderptsa.net Entra tenant (single-tenant config).
    static let entraTenantId: String = "a220d676-fd60-4d01-8742-d18944f51a66"

    /// MSAL redirect URI — must match the Info.plist URL scheme AND the
    /// "iOS / macOS" platform redirect URI in the app registration.
    static let entraRedirectUri: String = "msauth.net.wilderptsa.PTSABoard://auth"

    /// MSAL keychain access group (optional). Leave nil to use default.
    static let entraKeychainGroup: String? = nil

    /// Authority URL — `https://login.microsoftonline.com/<tenant>`.
    static var entraAuthority: String {
        return "https://login.microsoftonline.com/\(entraTenantId)"
    }

    /// The Microsoft Graph scopes the app needs. Calendar.ReadWrite.Shared
    /// is required to write to Calendar@wilderptsa.net for users that have
    /// write access; everyone else falls back to read.
    ///
    /// NOTE: MSAL iOS reserves `openid`, `profile`, and `offline_access` —
    /// they are added automatically to every request and MUST NOT appear
    /// here, or `acquireToken` fails with `MSALInternalError -42000`. They
    /// are still granted in the Entra app registration where they belong.
    static let graphScopes: [String] = [
        "User.Read",
        "Calendars.ReadWrite.Shared",
        "Mail.Send",
        "User.ReadBasic.All"
    ]

    /// The shared mailbox / calendar that hosts the PTSA calendar.
    static let sharedCalendarMailbox: String = "Calendar@wilderptsa.net"

    /// Shared Microsoft 365 calendars shown in the Calendar tab. These are
    /// read directly from Microsoft Graph with delegated permissions, so each
    /// signed-in board member must have access to the mailbox/calendar in M365.
    static let sharedGraphCalendars: [SharedGraphCalendar] = [
        .init(id: "Calendar@wilderptsa.net", mailbox: "Calendar@wilderptsa.net", name: "Events")
        // Add more once board members have delegated access, for example:
        // .init(id: "art@wilderptsa.net", mailbox: "art@wilderptsa.net", name: "Art")
    ]

    // MARK: - WordPress / WooCommerce

    /// The public URL of the WordPress site.
    static let wordpressBaseURL: URL = URL(string: "https://wilderptsa.net")!

    /// REST API base for the PTSA Tools mobile endpoints exposed by the
    /// WordPress plugin (`Azure_PTSA_REST_API`). Every WP/WooCommerce
    /// operation the app performs goes through here — auth is the user's
    /// Entra id-token, validated server-side. No consumer keys or
    /// application passwords are needed.
    static var ptsaRestBase: URL { wordpressBaseURL.appendingPathComponent("wp-json/ptsa/v1") }

    // MARK: - Authorization

    /// Email addresses (case-insensitive) allowed to check off / complete
    /// items on the technology backlog. Everyone in @wilderptsa.net can add
    /// and view; only these users can mark items complete.
    static let todoAdminEmails: Set<String> = [
        "OWNER@wilderptsa.net".lowercased()
    ]

    /// The email domain that the app accepts. Logins for any other domain
    /// are rejected after the MSAL handshake.
    static let allowedEmailDomain: String = "wilderptsa.net"
}
