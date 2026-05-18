# PTA Tools Plugin — To-Do

## Planned Modules

### Page Holding
**Priority:** Medium
**Status:** Planned

A module that allows pages to be put into a "holding" state with a full-viewport overlay banner, without changing the publish status. Ideal for seasonal event pages (e.g. Art Night, Carnival) that become outdated after the event ends.

**Key features:**
- Meta-based toggle per page — page stays Published (no broken links, menus, or SEO impact)
- Full-viewport sticky banner overlay with customizable message (e.g. "This event has been and gone this year, but we'll be back with more fun next year!")
- Page content remains visible below the banner if the user scrolls
- Configurable banner background color/image, CTA button text and link
- Default message template in module settings, with per-page override
- Optional: auto-enable holding mode after a configured end date
- Dashboard widget or list view showing all pages currently in holding mode
- Bulk toggle from PTA Tools admin

**Implementation notes:**
- New module toggle "Page Holding" on the main PTA Tools dashboard (default off)
- Per-page meta box in the WordPress editor with toggle + message fields
- Frontend: lightweight CSS overlay (position sticky, 100vh), no JS dependencies
- No new database tables needed — uses post meta only

---

## Backlog / Ideas

_Add future module ideas and improvements here._

---

## Bugs / Investigations

### Calendar Embed module: only 3 of 4 calendars visible
**Priority:** Medium
**Status:** Open

On the Calendar Embed module page, the available-calendars list shows 3 cards. There are actually 4 calendars on the `calendar@wilderptsa.net` shared mailbox — a new **Theater** calendar was added but is not appearing.

**Open questions:**
- Should newly added calendars on `calendar@wilderptsa.net` auto-discover and appear in the module UI? (Expected behavior per Calendar Embed module docs.)
- Is the discovery cached? If so, where, and how do we force a refresh?
- Can the UI grid handle 4 cards across, or does it wrap / truncate at 3? (Possible CSS / responsive layout issue masking a discovery that's actually working.)

**Investigation steps:**
- Check the Calendar Embed module settings page — is there a "Refresh calendars" / "Re-sync" button?
- Inspect the underlying Microsoft Graph call result (`class-calendar-graph-api.php`) to see whether the Theater calendar is returned by Graph but filtered out by the UI, or not returned at all (permissions / sharing).
- Verify the new Theater calendar is shared with the same delegate / has the same access level as the other 3 in `calendar@wilderptsa.net`.
- Test the admin UI grid with 4+ cards to confirm the layout supports it.
