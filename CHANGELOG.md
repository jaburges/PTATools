# Changelog

Release notes for PTA Tools, newest first. Earlier versions are on the [wiki changelog](https://github.com/jaburges/PTATools/wiki/Changelog) and the [GitHub releases](https://github.com/jaburges/PTATools/releases).

## 3.147.182 — PTA Products block

### Added

- **PTA Products block for sidebars and pages.** Add "PTA Products" from the Widgets category in the block or widget editor and set everything by clicking: pick a category from a dropdown, tick specific products from a searchable list (and put them in order), or both. A **Show input fields** switch decides what the cards do. Off: each card shows the image, name, price and a Select options / View product button that opens the product page, and nothing is added from the card. On: products with product fields show them on the card with Add to cart, as on the yearbook order page. Family memberships and products with options always open their page. Columns (1 for a sidebar, up to 4), image, price and button text are adjustable, and the editor shows a live preview. The same cards are available as `[pta-products category="membership" fields="off"]` (also `products="12,34"`, `columns`, `limit`, `image`, `price`, `button`). Existing product grids, including the yearbook order page, are unchanged.

## 3.147.181 — Breathing room around newsletter emails

### Changed

- **Newsletters keep 5px clear of the edge.** Every newsletter now has a 5px buffer inside the white email box, so headings and anything else that ran edge to edge no longer touch it, and 5px between the box and the screen edge on phones, where the box fills the width. It is one buffer around the whole email, not extra padding per section, so section spacing is unchanged; the dark footer sits inside it too. It is added when the email is sent (test sends, campaigns and order-rule emails), so existing and scheduled campaigns get it without editing, and the Review step preview shows it. A 600px-wide header image is sized to 590px so Outlook keeps the email at 600px.

## 3.147.180 — RSVP for more than one person

### Added

- **Bring guests with − and +.** The RSVP box on an event page shows − and + buttons around your name: tap + and it reads "Jamie +1", "Jamie +2" and so on, then tap **RSVP**. On event lists (`[up-next]`, `[azure_calendar_events]`, /events) tapping **RSVP** opens the same picker with **Confirm** and × to close; nothing is saved until Confirm. The RSVP is filed under the signed-in person (first name, else display name) and afterwards shows as "You're going: Jamie +1." on the event page and **Going ✓ +1** on lists. The picker never goes past the event's guest limit or the spaces left.

### Changed

- **Guests are allowed by default.** An event with the *Plus-guests allowed per RSVP* box left blank now allows up to 5 guests (it used to allow none). Enter 0 for events where people RSVP only for themselves. Capacity still counts guests.

## 3.147.179 — Automatic backups run, restorable database dumps, accurate backup widget

### Fixed

- **Automatic backups run.** "Run backups automatically" never scheduled anything: the scheduler was set up from inside WordPress's `init` step and hooked itself onto that same step, which WordPress does not run. The daily backup and the weekly cleanup are now scheduled on the first admin or cron request after the update.
- **Database dumps restore correctly.** When `mysqldump` was not available, the PHP export wrote a random 64-character code in place of every `%` (a side effect of WordPress's `esc_sql()`), so a restore would have corrupted URLs, CSS percentages and serialized settings. New dumps write real `%`, and restoring an older PHP dump turns the codes back into `%`. The container image now includes `mysqldump`, which takes a consistent snapshot in one transaction.
- **The Backup Status widget counts your backups.** It read activity-log entries that the backup job never writes, so it always said 0. It now reads the backup list, shows the last good backup even after a failure, and says when the next automatic backup is due (or that automatic backups are off or not yet scheduled).
- **Cleanup deletes whole backups.** Expired backups had only their manifest removed, leaving every archive behind in storage. Cleanup now removes all of a backup's files, keeps the row (and retries next week) if any file could not be deleted, and always keeps the three newest completed backups whatever their age. It no longer deletes activity-log history at the backup retention period; that housekeeping keeps 90 days.

## 3.147.178 — Bullets in sent newsletters, Pacific times on calendar hovers, RSVP on event lists

### Fixed

- **Bulleted and numbered lists show in sent newsletters (#17).** The CSS added at send time (for test sends and campaigns) set list items to `display: block`, which removes the bullet or number in Gmail and every other client that reads `<style>`. The editor never had that CSS, which is why drafts looked fine. List items now stay list items. Lists also get their bullet style and indent written inline, so clients that strip `<style>` keep them too. HTML saved by older versions is corrected when it is sent.
- **Calendar hover times are in Pacific time (#20).** The calendar took the time text from each event as it arrived and ignored its timezone. Outlook sends times in UTC when the site is set to UTC or to a plain offset such as UTC−7, so a 10 AM meeting showed "5:00pm" on hover. Times are now converted to the site timezone (Settings → General) before the calendar sees them. The hover text is written by the server in that timezone, and "today" follows the site clock, so all visitors see the same times wherever they are. All-day events and daylight-saving changes are covered. The `[azure_calendar_events]` list also formats times in the site timezone.
- **Subscribe on My Account → Signups (#21)** opens the same calendar-app chooser page as the reminder emails, instead of a bare `webcal://` link that Android browsers ignore.
- **`[up-next]` loads its styles.** The plugin loads `[up-next]` while the page is already rendering, after the point where it would add its stylesheet, so its Join buttons could appear unstyled. The styles now load in that case.

### Added

- **Donate after signing up (#18).** In the sign-up sheet editor, set *Donate after sign-up* to a donation page link, with optional button text and amounts (whole dollars, $5 to $100; the default is $5, $10, $25, $50, $100). Then tick **Ask to donate** on the options that should prompt. When someone signs up for one of them, a donate button and the amount buttons appear right away; the page reloads when they tap **Done**. A `[pta-donate]` page preselects the amount they tapped. It is off unless both are set: a sheet without a donation link, or without any option ticked, shows no donate button and sends no donate wording in any email. Signing up for several options already worked by signing up for each row; group sign-ups set how many people.
- **Sheet organizers hear about sign-ups and cancellations.** Whoever created a sign-up sheet now gets a short email when someone signs up, changes their group size or withdraws. It covers both the sheet and My Account → Signups. The email says who it was (with their email), which options and how many people, and links to the sheet in PTA Tools. Replying goes to the volunteer. No email is sent when the organizer signs themselves up, or when the sheet has no creator or the creator's account is gone or has no email. Edit the wording under Emails → Messages (*Sheet organizer: new sign-up* / *cancellation*).
- **Sign-ups CSV per sheet (#18).** The download button on each sheet in PTA Tools → Volunteer Sign up gives one row per person per option: activity, time, name, email, number of people, and sign-up time.
- **RSVP dashboard widget (#26).** The WP Dashboard has an **Event RSVPs** widget, and PTA Tools → RSVP shows the same cards above its table. There is one card per event with RSVP on, soonest first. Each card shows the date, time and status (drafts are marked), people vs capacity, the deadline, and the RSVPs (name, email, guests, time). Each card has links to the full RSVP list, the CSV export and the event editor. Past events are collapsed. Only people who can manage PTA Tools see it.
- **RSVP beside "Join meeting" on event lists (#29).** This covers `[up-next]`, `[azure_calendar_events]` cards and the /events list.
  - Signed in: an **RSVP** button, or **Going ✓** with **Cancel**. These use the same checks as the event page (duplicates, capacity, deadline).
  - Signed out: **Log in to RSVP**, which comes back to the same page.
  - **Full** and **RSVPs closed** show when they apply. Nothing shows when RSVP is off.
  - The button matches the Join button and goes full width on phones.
  - `[up-next]`'s cached HTML holds no one's RSVP; each visitor's state is filled in per page view. In a newsletter the slot is a plain **RSVP** link to the event page.
- **How-to video cards.** `[pta-video-gallery]` lays out `[pta-video src="…" poster="…" title="…"]description[/pta-video]` cards: the video with its thumbnail, a title and a short description. `[pta-team-only message="…"]` shows its content to administrators and PTSA team (Azure AD) users, and the optional message to everyone else. Used on FAQ → How To.
- **Forms warn when no confirmation goes out (#19).** A form's Settings tab now says when the person filling it in gets the on-screen message but no email, and how to send them a copy (`{submitter_email}`).

### Changed

- **Mobile header (#28).** Members now get a small blue star in a circle on the corner of their avatar, with the tooltip "PTSA member". It replaces the "PTSA" text mark and the "PTSA Member" pill in the header. The header account menu keeps the avatar, name and caret on one line, and a long name ends with "…" instead of wrapping. Phones get tighter spacing. Profiles and the directory keep the text pill.

## 3.147.177 — RSVP in previews, and a pointer for editors

### Fixed

- **Previewing a draft event now shows the RSVP box to editors.** Before, the box only appeared on published events, so an editor previewing a draft saw nothing and couldn't tell whether RSVP was set up. The preview shows the count and guest picker, with the RSVP button disabled and "Preview: members can RSVP once this event is published." Members and visitors still see nothing on drafts, and nobody can RSVP to a draft.

### Added

- **A note for editors when RSVP is off.** If an event doesn't take RSVPs, people who can edit it now see "Only editors see this: RSVP is off for this event…" where the RSVP box would be. It says where to turn RSVP on and links to the editor. Members and visitors see nothing.

### Where to find it

- **Module switch:** PTA Tools dashboard → Calendar card → *RSVP* switch, or PTA Tools → Scheduling & Events → RSVP.
- **Per-event setting:** the event editor's right-hand sidebar → *Event* tab → **RSVP** panel at the bottom, below Categories → **Enable RSVP**.

## 3.147.176 — Product fields on Product Collection cards

### Changed

- **Product Collection cards show the product's fields.** On a card for a product whose category or tag maps to product fields, the Add to cart button is replaced by those fields (for example Child Name, Grade and Teacher) and an **Add to cart** button. This is how the yearbook appears in *Recommended products* on the order page. Logged-in parents pick a saved child, and that child's details fill in.
- **The card add uses the same checks and cart data as the product page.**
  - With JavaScript, it posts to `?wc-ajax=azure_pf_add_to_cart`, which runs WooCommerce's add-to-cart validation and the plugin's cart-item fields.
  - Missing fields are listed under the card and nothing is added.
  - On success the item and its values appear in the cart, the mini-cart and the header cart count.
  - Without JavaScript the card posts as a normal form. Errors show under the card, and a successful add returns to the card.
- **Built for phones.** Inputs on the card are full width, 44px tall and 16px text, so iPhones don't zoom into them.
- **Unchanged from 3.147.175:**
  - Family membership and variable products keep the *Select options* link to their product page.
  - Classic loops, shortcodes and the JS product blocks keep linking to the product page.
  - A background add without the fields is still refused.
  - Express checkout stays hidden on product pages and appears on the cart and checkout.

### Upgrade note

- **3.147.175 never reached the live site.** The release was published, but the site kept running 3.147.174, so its yearbook button still added in the background and was refused. After releasing this version, install it on the site, then purge the Front Door cache for the order page, which is cached for 5 minutes.

## 3.147.175 — Product fields are always collected before add-to-cart

### Fixed

- **"Add to cart" in grids and blocks for products with product fields.** Previously these buttons added the item in the background, so the fields were never shown and the add was refused. This hit the yearbook in the *Recommended products* block on the order page. These buttons now say **Select options** and open the product page. This covers:
  - Product Collection and All Products blocks
  - legacy product grid blocks
  - `[products]` shortcodes
  - related products and shop archives
  - the Store API.

  Products without fields still add from the grid as before.
- **Refused adds go to the product page.** If an add-to-cart is ever refused because the fields are missing, for example an old `?add-to-cart=` link, the shopper goes to the product page with "“…” needs a few details first. Fill them in below, then add it to your cart." The notice now survives that redirect for guests with an empty cart. A Store API add gets the same message, pointing at the product page.

### Changed

- **Field groups can be assigned to product tags** as well as categories (Selling → Product Fields). A product shows a group's fields when any of its categories or tags is ticked.
- **Express checkout is unchanged:** Apple Pay, Google Pay and Amazon Pay stay hidden on product pages for products with required fields, now including products mapped by tag. They appear on the cart and checkout.

## 3.147.174 — Event RSVPs

### Before you upgrade

1. **Turn RSVP on.** It is a new sub-module under Scheduling & Events and starts off. Use the RSVP switch on the PTA Tools dashboard (Calendar card) or on PTA Tools → RSVP.
2. **New table `wp_azure_event_rsvps`.** It is created on the first admin, AJAX or cron request after the upgrade, or by the first visitor to an event page if that comes first. No existing data changes, and nothing needs to run by hand.
3. **Nothing is RSVP-enabled by default.** Each event needs *Enable RSVP* ticked in its editor.

### RSVP (new)

- **Event editor:** a new **RSVP** box on each event has *Enable RSVP*, an optional capacity (guests count toward it), an optional deadline, and how many plus-guests each person may bring (0–20). Without a deadline, RSVPs close when the event starts.
- **Event page:** signed-in people see an **RSVP** button, a guest picker when guests are allowed, and a count of spots taken. After they RSVP they see a **Cancel RSVP** button. Logged-out visitors are asked to log in and are brought back to the event. Full and closed events say so. `[event_rsvp id="…"]` shows the same box on any page.
- **Rules:** one RSVP per person per event, enforced by the database. The request needs a valid nonce and a signed-in account. A party is refused if it would exceed capacity, with the number of spaces left. People can cancel after the deadline. Someone who cancels can RSVP again.
- **Emails:** the confirmation email has the event title, date and time, location, description, party size, and the person's own https **Subscribe** link. A cancellation email is sent when they cancel. Both can be edited in Emails → Messages under *Events*.
- **Calendar feed:** RSVP'd events are added to the same private feed as volunteer shifts (`?pta_volunteer_ics=<token>`, offered by the `?pta_volunteer_subscribe=<token>` page). Each RSVP has a stable UID, so a subscribed calendar updates it in place. An event leaves the feed when the RSVP is cancelled, when the event is trashed or unpublished, or once it is over. A trashed event keeps its RSVPs, so Restore brings them back. Deleting an event permanently deletes its RSVPs.
- **The feed works without the Volunteer module.** With RSVP on and Volunteer off, the private feed and subscribe page are still served.
- **Admin:** PTA Tools → RSVP lists every RSVP-enabled event with people going, capacity, deadline and guests allowed. Each event has a list of RSVPs (name, email, guests and RSVP time, in site time) and a **CSV export** that neutralises spreadsheet formulas.
