# Admin

## Who this is for

Admin is intended for senior team members who need broad management access to
projects, contracts, and commercial records, without the platform-management
powers reserved for Super Admin.

!!! important "Admin is platform-wide, not scoped to one organisation"
    Admin accounts are not limited to a single organisation the way Client
    accounts are. An Admin user can see and work in projects belonging to any
    organisation on the platform, the same as a Super Admin can. If your
    organisation intends Admin accounts to only manage your own company's
    projects, this is a governance matter to manage by policy (who you give an
    Admin account to), not something SureSign restricts for you today.

!!! important "Correction (2026-09-14) — superseded by the Full Parity Access Expansion"
    This page previously stated flat "Admin cannot access Users/AI Config/
    Storage/System Logs/Audit Log" and "Admin cannot manage platform-wide
    settings" claims. Both are now stale relative to the **Full Parity
    Access Expansion (2026-08-26)** — see
    [Super Admin Configurable Admin Access](../super-admin/admin-access.md)
    for the full, authoritative architecture. Corrected below.

## What Admin can do by default

- Create, view, and manage **projects, contracts, RFIs, variations, payment
  applications, documents, and reports** across any organisation.
- Use **AI contract and subcontract analysis**.
- Access the **admin panel**, including Companies, Projects, Documents, and
  every one of the 15 default-baseline modules (`AdminAccess::defaultKeys()`)
  — a new Admin always receives this baseline automatically, with no Super
  Admin decision needed.
- Create and manage **Appointments** — always assigned to themselves (Admin
  cannot leave an appointment unassigned or assign one to someone else, and
  can view but not manage a Super-Admin-created unassigned appointment);
  this is enforced inside the controller itself, not just at the route
  level. Admin can view the list of Appointment Types but cannot create,
  edit, or delete one — that's Super Admin only. Admin can view and manage
  **only their own** weekly availability, date overrides, and blocked
  periods, and cannot use the Super-Admin-only scheduling override. See
  [Appointments & Scheduling](../super-admin/appointments.md).
- Manage **general platform settings** — platform name, support email, max
  upload size, and the global feature flags (document generation,
  white-label, self-registration). Confirmed reached via the profile
  popover's "Settings" link and deliberately kept OUTSIDE the configurable
  `admin.module.*` catalogue — every Admin can reach these regardless of
  their Access configuration.

## Configurable modules (default OFF, a Super Admin can grant any of them)

Since the Full Parity Access Expansion (2026-08-26), **Users, AI Config,
Application Monitoring, Storage, Support, Announcements, System Logs, and
Audit Log** are no longer flat "Super Admin only" — they are genuine
`admin.module.*` catalogue keys a Super Admin can grant to a specific Admin
individually (Users → open an Admin → **Access** section). A brand-new
Admin starts with **none** of these eight; only the 15 default-baseline
modules above are automatic.

- **Users** has one deliberate carve-out even when granted: an Admin can
  never act on, see, or create/promote a **Super Admin** account (invite,
  edit, ban/unban, deactivate, password actions, role-change — all blocked
  specifically for a Super Admin target; a Super Admin actor is unaffected).
  The Access-configuration endpoints themselves
  (`GET`/`PUT /users/{id}/permissions`) remain `role:Super Admin` ONLY
  regardless of any granted `admin.module.users` — an Admin can never
  configure any Admin's Access, full parity or not.
- The other seven (AI Config, Application Monitoring, Storage, Support,
  Announcements, System Logs, Audit Log) have full parity with Super Admin
  once granted — no further carve-out.

Do not assume any specific Admin account currently holds (or lacks) any of
these eight — it depends entirely on what a Super Admin has configured for
that individual account. "Admin cannot do X" is only ever true for the
*default*, freshly-created Admin, never a blanket platform rule.

## What Admin cannot do, regardless of configuration

- Cannot invite, edit, ban/unban, or otherwise act on an existing
  **Super Admin** account, or create/promote one — see the Users carve-out
  above. This is the one restriction granting `admin.module.users` does not
  lift.
- Cannot configure any Admin's Access permissions
  (`GET`/`PUT /users/{id}/permissions`) — Super Admin only, unconditionally.
- Cannot use the Super-Admin-only Appointments scheduling override, or
  manage another user's availability (see above).

## Where to find Admin tools

Signing in as Admin takes you to the same **admin dashboard** as Super
Admin. The sidebar reflects that Admin's actual configured Access — the 15
default modules always, plus any of the eight configurable ones a Super
Admin has granted them individually.

## Related

- [Super Admin](super-admin.md)
- [Super Admin Configurable Admin Access](../super-admin/admin-access.md) —
  the full architecture this page summarises
- Client is documented in the public User Guide's Roles section.
