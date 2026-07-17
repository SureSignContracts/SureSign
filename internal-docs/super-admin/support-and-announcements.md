# Support Ticket Administration and Platform Announcements

Covers the internal (Super Admin / Admin) side of the Help & Support Hub —
what a customer sees and submits is documented in the public User Guide's
[Help Center](../../docs/help-center/overview.md) page instead.

## Who can access this

Both Super Admin and Admin (`role:Super Admin|Admin` on the backend). The
Admin sidebar hides both "Support" and "Announcements" (marked
`superAdminOnly` in the sidebar's own configuration), but an Admin account
that navigates to `/admin/support` or `/admin/announcements` directly can use
either in full — this is a sidebar-visibility choice, not an access
restriction. See the note on [Admin](../roles/admin.md) for more detail.

## Support Ticket Administration (`/admin/support`)

Every ticket a Client (or Admin/Super Admin) submits from **Contact Support**
appears here, across every organisation, in a real paginated list (20 per
page) — filterable by status/category/priority and searchable by reference
or subject. There is no per-organisation filtering in the UI today (the
backend accepts an `organization_id` query filter via direct API access).

For each ticket you can see:

- **Reference, category, priority, status** — status is one of Open
  (legacy only — no code path assigns it anymore), Waiting for Support,
  Waiting for You, Resolved, Closed. Valid transitions are centralized in
  `SupportTicketStatusService`, not scattered per-controller conditionals —
  the modal only offers the buttons that are actually valid from the
  ticket's current status, and the backend rejects anything else with a 422
  regardless.
- **Submitter and organisation** — always the authenticated user who
  submitted it; the frontend never lets a user claim to submit on behalf of
  someone else or another organisation.
- **Route/module/project/trade package context** — where in SureSign the
  user was when they opened the form, if they arrived via a context-aware
  support link and didn't remove the suggested context.
- **Screenshot** (optional, one per ticket, plus optionally one more per
  reply) — stored privately, never on a public disk. Access to the raw file
  is restricted to the ticket's own submitter or a Super Admin/Admin —
  deliberately narrower than the general same-organisation file-access rule
  used elsewhere in SureSign, since a support screenshot can capture more of
  a user's screen than an ordinary project document.
- **Diagnostics** (optional, opt-in) — browser, OS, viewport, language,
  timezone, app version. Never raw request data, tokens, or cookies; the
  backend allowlists exactly these fields and silently drops anything else a
  client might send.
- **Recent SureSign activity** (optional, opt-in) — up to the user's
  organisation's latest 20 `project_activities` entries (the same table the
  ordinary client-visible Project Activity feed already uses), never
  server/application logs.

None of the optional attachments (screenshot, diagnostics, activity) are ever
required — an empty/absent value just means the user didn't opt in.

### Conversation thread

Each ticket has a real threaded conversation (`support_ticket_messages`
table — not a JSON blob) shown chronologically below the original message.
Reply publicly and the customer gets a personal notification (and an email,
if Brevo is configured) with a direct link back to their request; the
ticket's status automatically moves to Waiting for You (unless it's already
Resolved/Closed — replying there doesn't silently reopen it, use the
explicit status buttons for that).

**Internal notes**: switch the reply composer to "Internal note" to leave a
note only Super Admin/Admin can ever see — internal notes are filtered out
of every Client-facing API response (the `GET .../messages` endpoint
excludes them entirely for a non-operator caller, it isn't just a frontend
hide), never trigger a customer notification, and never change the ticket's
status.

When a customer replies, every Super Admin and Admin gets a personal
notification (never `sendToOrganization()` — there's no narrower "support
team" recipient list in the platform today, so every platform operator is
the correct, audited recipient set). This does not also trigger an email —
only the original ticket submission and a resolved status change do, to
avoid emailing staff on every reply.

## Platform Announcements (`/admin/announcements`)

A single banner shown at the top of every user's Help Center — used for
planned maintenance windows, known issues, or general platform information.

Fields: title, message, severity (Information, Maintenance, Degraded
Service, Outage), an active flag, a start time, an optional end time, and an
optional link (must be an internal SureSign path or an `https://` URL —
`javascript:`/`data:` URIs and protocol-relative `//host` links are rejected
by the backend).

Only one banner is ever shown at a time — the most recently started
announcement that is currently active (flagged active, already started, not
yet ended). Creating a new active announcement does not automatically
deactivate an older one; if you want a clean handover, set the old one's end
time or deactivate it yourself.

There is no app-wide banner (outside the Help Center) today — see
`project-context.md` for why that was deliberately out of scope.

## Related

- [Admin](../roles/admin.md)
- [Super Admin](../roles/super-admin.md)
