# User Management (Super Admin)

## Who can use it

Super Admin, and any Admin granted the **Users** module under Access (see
below) — with one exception: an Admin can never manage a Super Admin
account (change its role/status, ban, remove, reset its password, etc.)
or create a new Super Admin account, no matter what they're granted. Only
a genuine Super Admin can do either of those. Super Admin accounts also
never appear in the Users list, or open at all, for an Admin — even by
its exact URL — regardless of what they're granted; only a genuine Super
Admin sees them.

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
| **Role** | Change between Super Admin, Admin, and Client. Changing someone TO Admin gives them full access to every configurable Admin module by default (see **Access**, below) — a Super Admin can then restrict them. Changing someone AWAY from Admin removes that configured access. An Admin managing this page (via the Users module grant) never sees Super Admin as a selectable role, and can't change the role of an account that's already Super Admin — only a genuine Super Admin can promote to, or act on, a Super Admin account. |
| **Access** *(Admin only, Super Admin viewer only)* | Choose exactly which Admin modules (Companies, Projects, Documents, Pricing, Users, and every other module a Super Admin has — see [admin-access.md](admin-access.md) for the full current list) this specific Admin can use. Only shown when the account's role is Admin, and only to a Super Admin viewer — an Admin can never open this for anyone, including themselves. Super Admin always has full, unrestricted access regardless of this setting — it can't be configured away. Removing a module here both hides it from that Admin's sidebar and blocks the underlying API directly, so it can't be reached by calling the API directly either. Granting **Users** lets that Admin manage ordinary Admin/Client accounts on this same page — it never lets them manage a Super Admin account or create a new one (see **Who can use it**, above). |
| **Remove User** | Removes the account (with confirmation). Keeps the account's organisation association — if this same person is invited again later (same email), they return to the same organisation, no onboarding needed. Ends all their active sessions immediately, permanently — even if the account is later restored, none of their old sessions come back. |
| **Remove & detach** | Client only, and only when the account currently belongs to an organisation. Removes the account and disconnects it from its organisation — the organisation itself, and everything in it (Projects, Contracts, Documents, Billing/subscription, branding), is never touched or deleted. If this same person is invited again later, they start over as a brand-new, unaffiliated invite and go through organisation onboarding again; their previous organisation does not come back. Ends all their active sessions immediately, same as Remove User. If this account is the *only* remaining Client on its organisation, you'll be asked to confirm a second time — the organisation will still exist afterward, it just won't have any Client who can sign in to it until someone is invited and onboards. |

Use **Remove User** for a temporary/accidental removal where the person is
expected to come back to the same company. Use **Remove & detach** when this
person is genuinely leaving that company for good — especially if their email
address might later be reused for a completely different customer
relationship, since Remove User would otherwise silently hand that new person
the old organisation's data.

There is no bulk "Remove & detach" — the Users page's multi-select **Remove
Selected** action always performs the ordinary Remove User (organisation
preserved) for every row in the batch.

## Safeguards

- You cannot change your own role, or deactivate or ban your own account.
- You cannot deactivate, ban, re-role, or remove **the last active Super
  Admin** — SureSign blocks this to prevent the platform being left without an
  administrator.
- Removing or detaching an account never deletes the organisation, its
  projects, contracts, documents, billing, or any other data — organisation
  deletion is a separate capability that doesn't currently exist in SureSign.

## Filters

Use the All / Active / Disabled filter on the Users list to find accounts
quickly.

## Related

- [Deactivate or ban a user](../workflows/deactivate-or-ban-user.md)
- [Roles](../roles/overview.md)
