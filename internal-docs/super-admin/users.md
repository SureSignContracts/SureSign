# User Management (Super Admin)

## Who can use it

Super Admin only.

## Where to find it

Admin panel → **Users**.

## Inviting a user

1. Select **Invite User**.
2. Enter the person's **Email** and choose a **Role** (Admin or Client — Super
   Admin invites are not offered from this quick-invite flow).
3. Send the invite.

The recipient gets a SureSign invitation email ("You've been invited to
SureSign") with an **Accept Invitation & Set Up Account** link. No password is
ever generated for you to share — the recipient chooses their own password
when they accept. The link is specific to that person, expires after 7 days,
and cannot be reused once they've completed setup. If they haven't provided a
first name, the email uses a generic greeting rather than guessing one from
their email address.

After setup, the recipient is taken to Login with a short-lived "Your
SureSign account is ready" message and their email address already filled
in — this is purely presentational and disappears on any later, ordinary
visit to Login. Signing in then takes them to the right place automatically:
a Client with no Organisation yet (every invited Client, until they complete
onboarding) or an Organisation that hasn't finished onboarding lands on the
Organisation onboarding wizard; an already-onboarded Client lands on their
normal workspace; Admin/Super Admin follow their normal admin destination —
they are never sent through customer Organisation onboarding.

Until the invitation is accepted, the account shows as **Unverified** on the
Users list — the same badge a self-registered user who hasn't verified their
email yet would show. There is currently no separate "Pending Invitation"
label and no resend action; if an invitation link expires before the
recipient uses it, remove the account and send a new invite.

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
