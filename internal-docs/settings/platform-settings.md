# Platform Settings

## Who can use it

Reached via the profile popover's own **Settings** link, not any
AdminSidebar module. Not uniformly Super Admin only:

- **General settings** and **Notification settings** below are
  deliberately kept OUTSIDE the configurable `admin.module.*` catalogue
  (`AdminController::settings()`/`updateSettings()`,
  `SuresignSettingController::updateNotifications()`) — every Admin can
  reach these regardless of their configured Access. See
  [Super Admin Configurable Admin Access](../super-admin/admin-access.md)'s
  "Deliberately left ungated" section.
- **AI settings** below is a genuine `admin.module.ai_config` catalogue
  key (`permission:admin.module.ai_config`) — configurable, but default OFF
  for a brand-new Admin (one of the eight modules the Full Parity Access
  Expansion added). A Super Admin can grant it to a specific Admin; "Super
  Admin only" is only accurate for the default, unconfigured case.

## Where to find it

Admin panel → **Settings**.

## General settings

- **Platform Name**
- **Support Email**
- **Maximum Upload Size (MB)**

Select **Save General Settings** to save this section.

## Notification settings

A list of event types that can trigger an email notification (for example a
payment application being submitted or certified). Toggle the events you want
enabled, then select **Save Notification Settings**.

!!! note
    An event being toggled on here is one of three conditions required for an
    email to actually send — the other two are a configured email sender and a
    valid recipient (see the public User Guide's Email Notifications page for
    the user-facing behaviour).

## AI settings

Enable or disable AI features platform-wide, choose the AI model, and set the
effort level used for analysis. See the public User Guide's "AI in SureSign"
section for what users experience when this is on or off.

## Related

- [Feature Flags](feature-flags.md)
- Company Branding is organisation-level, not platform-wide, and is documented
  in the public User Guide.
