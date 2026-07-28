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
- `super-admin/` — Companies, Users, Platform Branding, Security Actions, [Support Ticket Administration and Platform Announcements](super-admin/support-and-announcements.md), [Appointments & Scheduling](super-admin/appointments.md), [Pricing Management](super-admin/pricing-management.md), [Subscription & Billing](super-admin/subscription-billing.md) (foundation only — see status note)
- [`marketing/`](marketing/navigation.md) — public marketing navigation, sitemap, and contact-form delivery flow
- [`commercial/`](commercial/suresign-commercial-strategy-v1.md) — the approved business/commercial foundation for Subscription & Billing: [Commercial Strategy v1](commercial/suresign-commercial-strategy-v1.md) (positioning, plans, pricing philosophy, lifecycle) and [Entitlement Specification v1](commercial/suresign-entitlement-specification-v1.md) (the technical entitlement model that strategy implies) — read both before continuing any Billing implementation beyond the Phase 1–4 foundation
- `settings/` — Platform Settings, Feature Flags
- `workflows/` — platform onboarding and account-security workflows
- [`demo-environment/`](demo-environment/index.md) — the isolated demo company (Halden Grove Construction Ltd.) used for marketing, docs, and sales demonstrations: seeder architecture, `demo:seed`/`demo:reset`, and phase status. See also [`demo-environment/deployment.md`](demo-environment/deployment.md) for the permanent `demo.suresigncontracts.app` deployment (Dokploy, storage/DB/Redis isolation, rollback)

This tree is plain Markdown only; it does not yet have its own MkDocs build
configuration.
