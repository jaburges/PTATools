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
    /// Register a new "Mobile and desktop" app in
    /// https://entra.microsoft.com and paste its Application (client) ID here.
    static let entraClientId: String = "REPLACE_WITH_CLIENT_ID"

    /// The tenant ID (or "common"/"organizations") for the wilderptsa.net tenant.
    /// Single-tenant is recommended: use the tenant GUID for wilderptsa.net.
    static let entraTenantId: String = "REPLACE_WITH_TENANT_ID"

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
    static let graphScopes: [String] = [
        "User.Read",
        "Calendars.ReadWrite.Shared",
        "Mail.Send",
        "User.ReadBasic.All",
        "openid",
        "profile",
        "offline_access"
    ]

    /// The shared mailbox / calendar that hosts the PTSA calendar.
    static let sharedCalendarMailbox: String = "Calendar@wilderptsa.net"

    // MARK: - WordPress / WooCommerce

    /// The public URL of the WordPress site.
    static let wordpressBaseURL: URL = URL(string: "https://wilderptsa.net")!

    /// REST API base for WordPress core (users, etc).
    static var wpRestBase: URL { wordpressBaseURL.appendingPathComponent("wp-json/wp/v2") }

    /// REST API base for WooCommerce v3 (orders, products).
    static var wcRestBase: URL { wordpressBaseURL.appendingPathComponent("wp-json/wc/v3") }

    /// REST API base for the custom PTSA Tools endpoints we expose from
    /// the WordPress plugin in this repo (todo list, password reset triggers,
    /// auction-email triggers, etc).
    static var ptsaRestBase: URL { wordpressBaseURL.appendingPathComponent("wp-json/ptsa/v1") }

    /// WooCommerce REST authentication. Use a read/write WC API key generated
    /// at WP Admin → WooCommerce → Settings → Advanced → REST API.
    /// We send it as Basic auth over HTTPS.
    static let wooConsumerKey: String = "REPLACE_WITH_CK"
    static let wooConsumerSecret: String = "REPLACE_WITH_CS"

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
