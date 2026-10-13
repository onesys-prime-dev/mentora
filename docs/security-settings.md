# Security settings

System administrators set these for the whole platform at **Security settings**
(`/admin/settings/security`). Looking needs `settings.view`; saving needs
`settings.manage` and the password again. Each changed value is an audit entry
(`settings.changed`, with old and new). They apply from the next request.

| Setting | Default | What it does |
|---|---|---|
| Shortest password | 8 | Checked when a person chooses a password (accepting an invitation, a password reset). Common passwords and ones containing the person's name or email are always refused (Shield). 8 to 64. |
| Sign out after inactivity | 120 min | A signed-in person who makes no request for this long is signed out on their next click. 5 to 120 (the server's session files live 120 minutes). |
| Failed sign-ins before a lockout | 5 | After this many failures in a row for an email (counted since its last success) sign-in for that email is refused, **even with the right password**, until the lockout length has passed since the latest failure. 0 turns it off. |
| Lockout length | 15 min | See above. 1 to 1440. |
| Emailed sign-in code | administrators | Roles that must enter a 6-digit emailed code after the password. System administrators are always included and cannot be removed. |

## Notes

- The lockout is per email address and uses Shield's own record of attempts
  (`auth_logins`). Someone can lock another person's account by typing it wrong;
  that is the trade of any lockout. An administrator can sign the person in
  again by waiting, or by turning the lockout off.
- Turning the emailed code on for a role needs working mail
  (`php spark mail:test`, `docs/mail.md`): without it those people cannot finish
  signing in. In `development` the code is also written to the log.
- Inactivity is measured by requests to the app; a page left open does not keep
  a session alive.
- **Not covered:** "allowed sign-in methods" (email and password is the only
  method; magic links stay off), per-institution overrides, and the password
  rule for `php spark admin:create` (always 12 or more characters).
