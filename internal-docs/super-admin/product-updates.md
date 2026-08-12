# Product Updates ("What's New in SureSign")

Platform-level product-communication content — new features, improvements,
important updates, and tips — shown to authenticated users via a dismissible
modal after sign-in. Deliberately a separate system from
[Platform Announcements](support-and-announcements.md) (system status/outage
banner, Help Center only) and `SuresignNotification` (per-user operational
alerts, the notification bell) — see `CLAUDE.md`'s AI/Announcements context
for the architecture-discovery reasoning behind keeping these three distinct.

## Who can access this

Management (`/admin/product-updates`) is Super Admin **or** Admin
(`role:Super Admin|Admin` on the backend — both are platform-wide roles in
this codebase, see [Admin](../roles/admin.md)). Client users never see the
management page; they only ever see published updates via the customer-facing
modal/history page.

## Data model

- `product_updates` — title, summary, content, category (New Feature /
  Improvement / Important Update / Tip), optional CTA label + URL, audience
  (All Users / Client Users / Platform Operators — default Client Users),
  status (Draft / Published / Archived), `published_at` (set once, the first
  time status becomes Published — never reset by a later edit or
  re-publish), `created_by`/`updated_by`.
- `product_update_dismissals` — a lean per-(update, user) record, unique on
  that pair. A row exists only once a user has clicked "Don't show this
  update again" for that specific update — **not** a row fanned out to every
  user at publish time (unlike `suresign_notifications`). No row means "not
  dismissed yet," not "unread."

## Lifecycle

**Draft → Published → Archived**, plus re-publishing an archived update
(status back to Published; `published_at` is untouched since it was already
set). Deleting an update is supported (cascades its dismissal rows) but
archiving is the normal way to retire one.

**Editing a published update never resets who has already seen it** — an
edit is always the same row/id, so existing `product_update_dismissals` rows
are untouched regardless of what changed. If you want everyone (including
people who dismissed an earlier update) to see something new, publish a
**new** Product Update rather than editing an old one.

## Audience targeting

Three values: `all`, `client`, `operator`. A Client user only ever sees
`all`/`client` updates; a Super Admin/Admin (platform operator) only ever
sees `all`/`operator` updates — enforced server-side
(`ProductUpdate::pendingFor()`/the history endpoint), not just hidden in the
frontend. No per-organisation targeting exists.

## Customer-facing behaviour

- `GET /product-updates/pending` — published, audience-matched updates the
  authenticated user has never dismissed, newest first, bounded to the 5
  newest (`ProductUpdate::MAX_PENDING`) so a user returning after a long
  absence isn't shown months of backlog at once.
- `GET /product-updates/history` — every published, audience-matched update
  regardless of this user's own dismissal state (bounded to 50). Dismissal
  controls the automatic popup only, never access to history.
- `POST /product-updates/{id}/dismiss` — idempotent; always records the
  *authenticated* user, never a caller-supplied one.

The frontend's `WhatsNewLauncher` is mounted once in each authenticated app
shell (`AppLayout`, `AdminLayout`) inside their already-gated "normal shell"
render branch — so it structurally cannot show before authentication, a
forced password change, or (customer side) onboarding. "Close" is a
session-only suppression (`sessionStorage`, not persisted); "Don't show this
update again" persists server-side via the dismiss endpoint above.

## Related

- [Support Ticket Administration and Platform Announcements](support-and-announcements.md)
- [Admin](../roles/admin.md)
- [Super Admin](../roles/super-admin.md)
