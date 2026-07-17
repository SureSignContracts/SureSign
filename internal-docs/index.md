# SureSign Internal Documentation

This tree holds documentation about **administering the SureSign platform
itself** — company/tenant management, platform-wide settings, AI
configuration, feature flags, and platform-level user-account actions.

This is separate from `docs/`, which is the public SureSign User Guide
(intended for `docs.suresigncontracts.app`) and covers how customers use the
product day to day.

Nothing in this tree should be published to the public documentation site.

## Contents

- `roles/` — Super Admin and Admin role capabilities
- `super-admin/` — Companies, Users, Platform Branding, Security Actions, [Support Ticket Administration and Platform Announcements](super-admin/support-and-announcements.md)
- `settings/` — Platform Settings, Feature Flags
- `workflows/` — platform onboarding and account-security workflows

This tree is plain Markdown only; it does not yet have its own MkDocs build
configuration.
