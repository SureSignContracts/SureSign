# Deactivate or Ban a User

## Purpose

Remove a user's access to SureSign, either temporarily (deactivate) or for
cause (ban).

## Role required

Super Admin only.

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
