# Super Admin

## Who this is for

The Super Admin role is for the people who run the SureSign platform itself, or
who need full oversight and control across every organisation using it.

## What Super Admin can do

Super Admin has access to everything in SureSign, including every action
available to Admin and Client, plus platform-management actions that only Super
Admin can perform:

- See and work inside **every organisation's** projects and records.
- **Manage companies** (view all organisations, and add projects to any of them
  from the admin panel).
- **Manage users** (see [User Management](../super-admin/users.md)), including:
    - Inviting new users and assigning their role.
    - Activating and deactivating accounts.
    - Verifying or unverifying an account's email.
    - Banning and unbanning accounts, with a required reason for a ban.
    - Forcing a password reset, or setting a temporary password directly.
    - Revoking a user's active sessions.
    - Resetting a user's guided product tours.
    - Removing a user account.
- **Configure the platform**: platform name, support email, maximum upload
  size, feature flags, and notification settings (see
  [Platform Settings](../settings/platform-settings.md)).
- **Configure AI** platform-wide: enable/disable AI features, choose the AI
  model, and set the effort level (the user-facing behaviour is documented in
  the public User Guide's "AI in SureSign" section).
- View **Storage**, **System Logs**, and the **Audit Log**.
- Access **Support** tools.
- Manage **Appointment Types** and view/manage every **Appointment**
  regardless of who it's assigned to, including leaving one unassigned;
  view and manage **any eligible staff member's** weekly availability, date
  overrides, and blocked periods (not just their own); and use the
  scheduling override (with a required reason) to bypass availability
  validation for a manually created/rescheduled/assigned appointment — never
  same-staff overlap, which stays enforced regardless (see
  [Appointments & Scheduling](../super-admin/appointments.md)).

## Safeguards that protect the platform

SureSign will not let a Super Admin lock the platform out of administration:

- You cannot deactivate, ban, remove, or change the role of **the last active
  Super Admin account**. If you are the only Super Admin, these actions are
  blocked.
- You cannot change your own role, or deactivate or ban your own account, from
  the Users screen.

## Where to find Super Admin tools

Sign in and you are taken to the **admin dashboard**. The admin sidebar exposes
Companies, Projects, Documents, Users, Templates, Prompt Library, Find Company,
Billing, SureSign, and (Super Admin only) AI Config, Storage, Support, System
Logs, and Audit Log.

## Related

- [Admin](admin.md)
- [User Management](../super-admin/users.md)
- [Platform Settings](../settings/platform-settings.md)
- [Deactivate or ban a user](../workflows/deactivate-or-ban-user.md)
