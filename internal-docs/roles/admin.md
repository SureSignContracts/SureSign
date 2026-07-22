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

## What Admin can do

- Create, view, and manage **projects, contracts, RFIs, variations, payment
  applications, documents, and reports** across any organisation.
- Use **AI contract and subcontract analysis**.
- Access the **admin panel**, including Companies, Projects, Documents, and the
  other admin tools available to a non-Super-Admin.
- View and manage **support tickets** (`role:Super Admin|Admin` on the backend
  — see [Support Ticket Administration](../super-admin/support-and-announcements.md)),
  and manage the platform **announcement banner** the same way.
- Create and manage **Appointments** — always assigned to themselves (Admin
  cannot leave an appointment unassigned or assign one to someone else, and
  can view but not manage a Super-Admin-created unassigned appointment);
  unlike Support/Announcements above, this is enforced inside the
  controller itself, not just at the route level. Admin can view the list
  of Appointment Types but cannot create, edit, or delete one — that's
  Super Admin only. Admin can view and manage **only their own** weekly
  availability, date overrides, and blocked periods, and cannot use the
  Super-Admin-only scheduling override. See
  [Appointments & Scheduling](../super-admin/appointments.md).

!!! note "Sidebar visibility vs. actual access — verified 2026-07-19"
    Support and Announcements are marked "Super Admin only" in the Admin
    sidebar's own configuration (so an Admin account won't see the nav links),
    but the backend routes for both are `role:Super Admin|Admin`, not
    `role:Super Admin` alone — an Admin account that navigates to
    `/admin/support` or `/admin/announcements` directly can use them fully.
    This page previously stated Admin could not access Support at all, which
    was inaccurate; it's corrected here as of the Batch 5 Help & Support
    security review. Storage, System Logs, and Audit Log below have not been
    re-verified against their actual route middleware as part of this review
    — treat those specific claims with the same caution until checked.

## What Admin cannot do

- Cannot access **Users** — Super Admin only (`role:Super Admin`, strictly, on
  the backend).
- Cannot access **AI Config**, **Storage**, **System Logs**, or **Audit Log**
  from the sidebar — see the note above on sidebar visibility vs. backend
  access for these.
- Cannot invite users, change roles, or perform any of the account-management
  actions listed on the [Super Admin](super-admin.md) page (activate/deactivate,
  ban/unban, password resets, session revocation, tour resets). All of these
  require Super Admin.
- Cannot manage platform-wide settings (platform name, feature flags, AI
  configuration).

## Where to find Admin tools

Signing in as Admin takes you to the same **admin dashboard** as Super Admin,
with a reduced sidebar (no Users, AI Config, Storage, System Logs, or Audit
Log — see the note above regarding Support and Announcements specifically).

## Related

- [Super Admin](super-admin.md)
- Client is documented in the public User Guide's Roles section.
