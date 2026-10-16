# Changelog

Release notes for PTA Tools, newest first. Earlier versions are on the [wiki changelog](https://github.com/jaburges/PTATools/wiki/Changelog) and the [GitHub releases](https://github.com/jaburges/PTATools/releases).

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
