# Security Actions (Super Admin)

The main security-related actions available to a Super Admin all live on the
**Users** screen — see [User Management](users.md) for the full list:

- Deactivate / reactivate an account
- Ban / unban an account (with a required reason)
- Force a password reset
- Set a temporary password directly
- Revoke a user's active sessions

## Session behaviour you should know

- Banning, deactivating, forcing a password reset, or setting a temporary
  password all **end the affected user's existing sessions immediately** —
  they will need to sign in again.
- SureSign also re-checks every signed-in user's account status on each
  request, so if an account is deactivated or banned while the user is actively
  using SureSign, they are signed out on their very next action, not just their
  next fresh sign-in.

## Platform lockout protection

SureSign will not let you deactivate, ban, re-role, or remove **the last active
Super Admin account**, and you cannot take any of these actions against your
own account. This protects the platform from being left without an
administrator.

## Related

- [User Management](users.md)
- [Deactivate or ban a user](../workflows/deactivate-or-ban-user.md)
