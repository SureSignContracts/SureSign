# User Management (Super Admin)

## Who can use it

Super Admin only.

## Where to find it

Admin panel → **Users**.

## Inviting a user

1. Select **Invite**.
2. Enter the person's **Email** and choose a **Role** (Admin or Client — Super
   Admin invites are not offered from this quick-invite flow).
3. Send the invite.

The new account is created with a temporary password and an email
verification link is sent.

## Managing an existing user

Open a user to see their action panel:

| Action | What it does |
|---|---|
| Toggle **Active** | Deactivated users cannot sign in. |
| Toggle **Email Verified** | Marks the account's email as verified or not. |
| Toggle **Banned** | Requires a reason. Banned users cannot sign in; their active sessions are ended immediately. |
| **Force Password Reset** | The user must set a new password before doing anything else, and their existing sessions are ended. |
| **Set Temporary Password** | Generates a password for you to give the user directly; you can require them to change it on next login. Their existing sessions are ended. |
| **Revoke Active Sessions** | Signs the user out everywhere immediately. |
| **Reset Onboarding Tours** | The user's guided product tours will show again next time they sign in. |
| **Role** | Change between Super Admin, Admin, and Client. |
| **Remove User** | Removes the account (with confirmation). |

## Safeguards

- You cannot change your own role, or deactivate or ban your own account.
- You cannot deactivate, ban, re-role, or remove **the last active Super
  Admin** — SureSign blocks this to prevent the platform being left without an
  administrator.

## Filters

Use the All / Active / Disabled filter on the Users list to find accounts
quickly.

## Related

- [Deactivate or ban a user](../workflows/deactivate-or-ban-user.md)
- [Roles](../roles/overview.md)
