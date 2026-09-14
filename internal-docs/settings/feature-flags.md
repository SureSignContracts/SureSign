# Feature Flags

## Who can use it

Every Admin, not Super Admin only — from Admin panel → **Settings** →
Feature Flags. These flags are part of `AdminController::updateSettings()`,
confirmed deliberately kept OUTSIDE the configurable `admin.module.*`
catalogue (see
[Super Admin Configurable Admin Access](../super-admin/admin-access.md)'s
"Deliberately left ungated" section) — unlike the eight modules (Users, AI
Config, Application Monitoring, Storage, Support, Announcements, System
Logs, Audit Log) a Super Admin must individually grant to an Admin.

## What you can toggle

- **Document Generation** — turns automatic document generation on or off
  platform-wide.
- **White-label Branding** — turns organisation branding features on or off.
- **Self-registration** — reserved for a future self-service sign-up flow.

!!! note "Self-registration is not yet functional"
    SureSign does not currently have a self-registration sign-up flow. This
    toggle exists ready for when that feature is built, but switching it on
    today has no visible effect — all users must currently be invited by an
    Admin or Super Admin (an Admin needs `admin.module.users` granted; see
    [Admin](../roles/admin.md)).

Select **Save Feature Flags** to save your changes.

## Related

- [Platform Settings](platform-settings.md)
- [User Management](../super-admin/users.md)
