# PTSA Board iOS — Improvement ideas

A running list of follow-on features and refinements that didn't make it
into the first cut. None of these are blockers, but most would be high
value for board operations.

> Convention: each item is sized as **S / M / L / XL** based on rough
> implementation invasiveness (not calendar time), and tagged with which
> subsystems are affected.

---

## Payments & merchandise

### Tap-to-Pay on iPhone (M, services + entitlements)
- Use Apple's [Tap to Pay on iPhone](https://developer.apple.com/tap-to-pay/)
  to accept in-person card payments at events without a card reader.
- Requires the `com.apple.developer.proximity-reader.payment.acceptance`
  entitlement (must be requested from Apple) and integration with one of
  the supported processors: Stripe, Adyen, Square, or Worldline.
- Recommended: **Stripe Terminal SDK** because it already integrates well
  with WooCommerce orders. Workflow: pick an open order in the Orders tab
  → tap "Charge in-person" → present iPhone to the customer's card → on
  success, set the WooCommerce order to `processing` (or `completed` for
  shipping-not-required items).

### Stripe Connect for ad-hoc collections (L, services + backend)
- For non-WooCommerce collections (raffles at events, room-parent
  reimbursements, one-off donations), use Stripe Connect to issue
  short-lived payment links from inside the app — the link opens Apple
  Pay / Google Pay in a sheet.

### Square / Clover bridge (M, optional)
- Existing volunteer-led events sometimes already use a Square reader.
  An "Add Square sale" entry could let board members log those sales into
  the WooCommerce reporting system after the fact.

---

## Communication

### In-app messaging between board members (M, Graph)
- Use the [Microsoft Graph Chat API](https://learn.microsoft.com/graph/api/resources/chat)
  to back a small "Messages" tab. Each conversation maps to a Teams
  group chat or 1:1 chat. Permission scope: `Chat.ReadWrite`.
- Cheaper alternative: a single Teams channel ("PTSA Board iOS")
  surfaced via the `channelMessage` endpoint.

### Smart reply templates (S, models)
- Saved reply snippets ("Auction pickup info", "Tax-receipt language",
  "Volunteer ask") that fill the mailto body. Store in
  `wp-json/ptsa/v1/email-templates`.

### Push notifications (L, infra + backend)
- Use APNs + a webhook from WordPress for these triggers:
  - New WooCommerce order placed
  - Order moved to `failed` / `cancelled`
  - New tech-backlog item added
  - New auction bid received
- Probably cleanest via Azure Notification Hubs since you already use
  Entra ID.

---

## Orders & products

### Bulk-update orders (S, UI)
- Multi-select in the Orders list to mark several as `completed` /
  `cancelled` in one tap.

### Order timeline (S, services)
- Show the WooCommerce notes feed (`/orders/{id}/notes`) inline on the
  detail page, with the ability to add a note without leaving the page.

### Print shipping labels (M)
- Tie into Shippo / ShipStation / Pirate Ship via their REST APIs and
  print a 4×6 label to an AirPrint-enabled label printer.

### Inventory adjustments from the app (S)
- Quick `+1` / `-1` stock buttons on each product card when the product
  has `manage_stock = true`.

### Product variant management (M, UI)
- The current edit screen handles only top-level fields. Add a Variants
  section for `type: variable` products to edit per-variant SKU, price,
  and stock.

### Barcode / QR scanning (S, capture)
- VisionKit barcode scanner that jumps straight to the matching product
  by SKU. Useful for room-parent inventory counts.

---

## Calendar

### Multiple shared calendars (S)
- The PTA Tools plugin already supports multiple calendars in
  `calendar@wilderptsa.net`. Surface a calendar picker in the Calendar
  tab and remember the last choice.

### iCal subscribe in-app (S, EventKit)
- Add a "Subscribe in iOS Calendar" button that registers
  `webcal://wilderptsa.net/...` so events flow into the system Calendar
  too.

### RSVP / volunteer slots inline (M)
- For events that come from the Volunteer Sign-Up module, show slot
  status inline and let board members claim/unclaim themselves.

---

## Users & roles

### Bulk role changes (S)
- Multi-select user list → assign a role to many users at once.

### Invite new users (M)
- Form that creates a WP user via the plugin endpoint and emails them an
  Entra-issued onboarding link.

### Member directory with photos (S)
- Use Graph `/users/{id}/photo/$value` to show real headshots in the
  Users tab. Cache locally.

---

## Tech backlog

### Comments & subscriptions (M, plugin)
- Threaded comments on each backlog item, with "Subscribe" so the
  creator gets notified.

### Cross-link with GitHub Issues (M, plugin + UI)
- Optionally mirror a backlog item to a GitHub Issue in the repo of
  record (e.g. `pta-tools`).

### Voting / upvotes (S, plugin)
- A thumbs-up button so board members can signal which backlog items
  matter most.

---

## App polish

### Glass-card visual theme (S, UI)
- Adopt iOS 26's Glass material palette across the cards (currently we
  use system grouped backgrounds).

### Apple Watch companion (XL)
- A read-only "Today's orders" complication for the school day.

### Widgets (M)
- Home-screen widgets for "open orders count" and "next 3 calendar
  events".

### Live Activities (M)
- Show in-progress auction bids on the Lock Screen during an active
  auction window.

### Localization (S)
- Translate strings to Spanish — most PTSA families have at least one
  parent who'd prefer it.

---

## Security / ops

### Per-user audit log (M, plugin)
- Every order edit / role change / email trigger should record `who`,
  `when` and `what` so it's auditable later.

### Conditional Access (S)
- Add Conditional Access policies in Entra so the iOS app can only be
  used from compliant devices (e.g. with Intune enrollment).

### App attestation (M)
- Use `DeviceCheck` / App Attest in the WP plugin to reject calls from
  non-app callers using a leaked WC key pair.
