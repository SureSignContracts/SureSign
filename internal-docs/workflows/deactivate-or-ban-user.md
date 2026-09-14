# Deactivate or Ban a User

## Purpose

Remove a user's access to SureSign, either temporarily (deactivate) or for
cause (ban).

## Role required

For an ordinary Admin/Client target: Super Admin always, or an Admin who
has been granted the `admin.module.users` module (see
[Super Admin Configurable Admin Access](../super-admin/admin-access.md)) —
it is no longer a hardcoded Super-Admin-only action.

For a **Super Admin** target: Super Admin only, regardless of any
`admin.module.users` grant — an Admin can never deactivate, ban, or even
see an existing Super Admin account (the Users module's one deliberate
carve-out — see that same document). A Super Admin acting on another
Super Admin account is still subject to the last-active-Super-Admin
safeguard below.

## Steps — Deactivate

1. Go to Admin panel → **Users** and open the user.
2. Toggle **Active** off.
3. Confirm.

## Steps — Ban

1. Go to Admin panel → **Users** and open the user.
2. Toggle **Banned** on.
3. Enter a **reason** for the ban (required).
4. Confirm.

## Expected result

- The user cannot sign in (they will see "Your account has been deactivated."
  or "Your account has been banned.").
- Their existing sessions are ended immediately — if they are actively using
  SureSign, they are signed out on their next action.
- To restore access, toggle **Active** back on, or **Unban** — the user will
  need to sign in again either way.

## Linked modules

- [User Management](../super-admin/users.md)
- [Security Actions](../super-admin/security-actions.md)

## Safeguards

You cannot deactivate or ban your own account, or the last active Super Admin
account.

## Common mistakes

- Banning when deactivating would do — banning is intended for cause and
  records a reason; use deactivation for routine access removal (for example,
  someone leaving the company) where no reason needs recording.

## What to do next

If you need to restore access later, see [User Management](../super-admin/users.md).
