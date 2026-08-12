# Set Up a Company and First User

## Purpose

Get a new organisation and its first user into SureSign so they can start
working.

## Prerequisites

None beyond deciding who the first user will be.

## Role required

Super Admin.

!!! note
    There is currently no self-service way to create a brand-new organisation
    from the Companies page in the admin panel — the **Add Company** button
    there is not yet wired up. New organisations are set up outside the
    standard admin interface; contact your SureSign platform provider to have
    a new organisation created.

## Steps

1. Once the organisation exists, go to Admin panel → **Users** → **Invite
   User**.
2. Enter the first user's email and choose their role (typically Admin, or
   Client for a standard day-to-day user).
3. Send the invite. SureSign emails the person a SureSign invitation with an
   **Accept Invitation & Set Up Account** link — no password is generated for
   you to hand over.
4. The person opens the link, chooses their own password, and their account
   is activated. They land on Login next, with a short-lived "Your SureSign
   account is ready" message and their email already filled in.
5. The user signs in and is taken automatically to the right place: if they
   are the first user of a new organisation (or their organisation hasn't
   finished onboarding), they land on the onboarding wizard (profile, company
   details, branding) — documented in the public User Guide's Getting Started
   section. Not every role goes through this — Admin/Super Admin follow
   their normal admin destination instead, and a Client whose organisation
   has already completed onboarding lands on their normal workspace.

## Expected result

The new user can sign in, has completed onboarding, and their organisation's
branding is set up.

## Linked modules

- [Roles](../roles/overview.md)
- [User Management](../super-admin/users.md)
- Company Branding is documented in the public User Guide.

## Notifications

The invited user receives a SureSign invitation email with an invitation
link, not a standard email-verification link — accepting it sets up their
account and verifies their email in one step.

## Common mistakes

- Inviting a user with the wrong role — only a Super Admin can change this
  later, so check before sending.

## What to do next

[Create a project](new-project.md) for the new organisation.
