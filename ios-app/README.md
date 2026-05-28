# PTSA Board — iOS App

A custom-built iOS app for the Wilder PTSA board. Sign in once with your
`@wilderptsa.net` Microsoft account, then use Face ID after that. Manage
WooCommerce orders, products, and the shared calendar; trigger password
resets and bulk emails; and keep a shared technology backlog — all without
ever touching the WordPress admin UI.

> **Repo location:** this app lives at the `ios-app/` folder of the
> `pta-tools` monorepo. The matching WordPress plugin endpoints it expects
> (`/wp-json/ptsa/v1/...`) should be added to the PTA Tools plugin.

---

## Features

- **Microsoft Entra ID sign-in (MSAL)** restricted to `@wilderptsa.net`
- **Face ID / Touch ID / Optic ID** unlock on subsequent launches
- **Five tabs** — Orders · Products · Calendar · Users · Tech Backlog
- **Top-right avatar menu** for personal password reset, theming, email
  triggers, and sign-out
- **Custom UI** — SwiftUI, mobile-first, no WordPress chrome anywhere
- **Per-row contextual actions** in orders (auction pickup info, ticket
  resends, tax receipts) plus a sheet for sending the auction-items
  bulletin to a list of recipients

See [`improvements.md`](./improvements.md) for the longer wishlist.

---

## Project layout

```
ios-app/
├── PTSABoard.xcodeproj/             # Xcode project (synced groups, Xcode 16+)
├── PTSABoard/                       # Swift sources
│   ├── PTSABoardApp.swift           # App entry
│   ├── Info.plist                   # MSAL URL schemes / Face ID strings
│   ├── Config/AppConfig.swift       # Tenant, client ID, WP base URL, allow-list
│   ├── Models/                      # WCOrder, WCProduct, WPUser, GraphEvent, TodoItem, UserProfile
│   ├── Services/                    # AuthService (MSAL), Graph, Woo, WP, Keychain, Biometric
│   ├── Views/                       # SwiftUI screens (per tab)
│   └── Assets.xcassets/             # AppIcon + AccentColor
├── improvements.md                  # Follow-on ideas
└── README.md                        # this file
```

The Xcode project uses [synchronized filesystem groups][synced-groups], so
Xcode picks up new files automatically when you drop them under
`PTSABoard/`. There's no `Sources` list to maintain.

[synced-groups]: https://developer.apple.com/wwdc24/10171

---

## Prerequisites

- macOS with **Xcode 16+** and the iOS 17 SDK
- An Apple Developer team (free or paid) to sign the build
- Admin access to your Entra ID tenant to register the iOS app

---

## One-time setup

### 1. Register the iOS app in Entra ID

1. Go to <https://entra.microsoft.com> → **Applications** → **App registrations** → **+ New registration**
2. Name: `Wilder PTSA Board (iOS)`
3. Supported account types: **Single tenant**
4. Redirect URI: select **Public client / native (mobile & desktop)** and use
   `msauth.com.burgess.PTAtools://auth`
5. Save, then on **Authentication**:
   - Add an **iOS / macOS** platform redirect with bundle ID
     `com.burgess.PTAtools`. Azure will generate the correct
     `msauth.<bundle>://auth` URL.
   - Allow public client flows: **Yes** (mobile flows require this).
6. On **API permissions**, add delegated Microsoft Graph permissions:
   - `User.Read`
   - `User.ReadBasic.All`
   - `Calendars.ReadWrite.Shared`
   - `Mail.Send`
   - `offline_access`, `openid`, `profile`
   - **Grant admin consent** for the tenant.
7. Copy the **Application (client) ID** and **Directory (tenant) ID**.

### 2. Generate WooCommerce REST keys

In WP Admin → **WooCommerce → Settings → Advanced → REST API → Add key**:
- Description: `PTSA Board iOS`
- User: an account with `shop_manager` (or `administrator`) role
- Permissions: **Read/Write**
- Save and copy the consumer key/secret pair.

### 3. Add the matching WordPress endpoints

The app talks to two sets of REST endpoints from your existing PTA Tools
WordPress plugin:

| Path                                           | Used for                                                              |
| ---------------------------------------------- | --------------------------------------------------------------------- |
| `/wp-json/wc/v3/orders` (built-in)             | List, update, refund, note WooCommerce orders                         |
| `/wp-json/wc/v3/products` (built-in)           | List, create, update products                                         |
| `/wp-json/ptsa/v1/media`                       | Image upload for product photos (binary body, JPEG)                   |
| `/wp-json/ptsa/v1/users`                       | Search/list users with `email`/`roles` (Entra JWT auth)               |
| `/wp-json/ptsa/v1/users/{id}` (PUT)            | Update roles                                                          |
| `/wp-json/ptsa/v1/wp-roles`                    | List assignable WordPress roles, including custom roles               |
| `/wp-json/ptsa/v1/pta-roles/org`               | Load PTA departments, roles, assignments, and vacancies               |
| `/wp-json/ptsa/v1/pta-roles/assignments`       | Assign a user to an existing PTA role                                 |
| `/wp-json/ptsa/v1/pta-roles/assignments/{id}`  | Remove an active PTA role assignment                                  |
| `/wp-json/ptsa/v1/users/reset-password`        | Trigger a reset email for a given user                                |
| `/wp-json/ptsa/v1/users/reset-password-self`   | Trigger a reset for the signed-in user                                |
| `/wp-json/ptsa/v1/todos`                       | GET/POST tech-backlog items, with server-created GitHub issues        |
| `/wp-json/ptsa/v1/todos/{id}` (PUT/DELETE)     | Update / delete a backlog item                                        |
| `/wp-json/ptsa/v1/auction/email-items` (POST)  | Send the bulk "auction items list" email via the website mailer       |

All `ptsa/v1` endpoints should:

1. Require an `Authorization: Bearer <MSAL access token>` header
2. Validate the JWT against the configured Entra tenant
3. Confirm `upn`/`preferred_username` ends with `@wilderptsa.net`
4. Map the caller to a local WP user (e.g. via SSO) for capability checks

The app is graceful when these endpoints aren't deployed yet — it falls
back to the built-in `wp/v2/users` endpoint where it can, and it surfaces a
human-readable error in the UI everywhere else. You can therefore ship the
app and the plugin endpoints separately.

### 4. Fill in `AppConfig.swift`

Open `PTSABoard/Config/AppConfig.swift` and replace the `REPLACE_*` values:

```swift
static let entraClientId   = "00000000-0000-0000-0000-000000000000"
static let entraTenantId   = "11111111-1111-1111-1111-111111111111"
static let wooConsumerKey  = "ck_..."
static let wooConsumerSecret = "cs_..."
```

Also update `todoAdminEmails` with the email address(es) that should be
allowed to mark tech-backlog items as complete.

> **Don't commit secrets.** For shared development we recommend moving
> these values into a `Config.local.xcconfig` that's gitignored, and
> reading them back via build-setting injection. The Info.plist already
> declares a custom URL scheme so a per-developer client ID isn't required.

### 5. Build and run

1. Open `PTSABoard.xcodeproj` in Xcode.
2. Xcode will resolve Swift Package dependencies (MSAL ~> 1.5).
3. Select your team under **Signing & Capabilities**.
4. Pick a physical device (Face ID requires real hardware) and **Run**.

To produce an IPA for ad-hoc distribution: **Product → Archive → Distribute App
→ Ad Hoc** (or **Development**) and pick your provisioning profile.

To submit to the App Store / TestFlight: **Product → Archive → Distribute App
→ App Store Connect**.

---

## Architecture notes

- **Auth state machine** — `AuthService` exposes `.loading / .signedOut /
  .locked / .signedIn`. After the first interactive sign-in we persist a
  "biometric enrolled" flag in Keychain; on subsequent launches the user
  lands in `.locked` and must pass Face ID before the silent token
  refresh runs.
- **Token strategy** — `graphAccessToken()` always returns a non-expired
  Microsoft Graph token, refreshing silently when needed. The same token
  is reused as a Bearer for the custom `ptsa/v1` WordPress endpoints
  (your plugin validates the JWT server-side).
- **WooCommerce auth** — Basic auth over HTTPS using the consumer key
  pair. WooCommerce REST is well-trusted for this so we don't proxy
  through Graph.
- **Synced filesystem groups** — adding a `.swift` file under
  `PTSABoard/` is enough; no `pbxproj` edits required.

---

## Troubleshooting

- **`MSAL init failed`** — usually a typo in `entraClientId` or a
  missing iOS-platform redirect URI in the registration.
- **Login succeeds but `Only @wilderptsa.net accounts may use this app`** —
  expected for any other tenant. Sign out and try a `@wilderptsa.net` account.
- **`HTTP 401` on WooCommerce calls** — re-check your consumer key/secret
  and confirm "Read/Write" permission was selected.
- **`HTTP 404` on `/ptsa/v1/...`** — your plugin doesn't expose that
  endpoint yet; the UI degrades gracefully.

---

## License

Internal — Wilder PTSA. Not for redistribution.
