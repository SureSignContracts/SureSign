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

1. Once the organisation exists, go to Admin panel → **Users** → **Invite**.
2. Enter the first user's email and choose their role (typically Admin, or
   Client for a standard day-to-day user).
3. Send the invite. SureSign creates the account with a temporary password and
   sends an email verification link.
4. Give the user their temporary password if it was not sent automatically, or
   have them use **Forgot password** to set their own.
5. The user signs in and, if they are the first user of a new organisation,
   is guided through the onboarding wizard (profile, company details,
   branding) — documented in the public User Guide's Getting Started section.

## Expected result

The new user can sign in, has completed onboarding, and their organisation's
branding is set up.

## Linked modules

- [Roles](../roles/overview.md)
- [User Management](../super-admin/users.md)
- Company Branding is documented in the public User Guide.

## Notifications

The invited user receives an email verification link.

## Common mistakes

- Inviting a user with the wrong role — only a Super Admin can change this
  later, so check before sending.

## What to do next

[Create a project](new-project.md) for the new organisation.
