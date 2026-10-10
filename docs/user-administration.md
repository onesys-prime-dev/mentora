# User administration

System administrators manage people across every institution from
**Users** (`/admin/users`). Looking and exporting need `users.view`; changing
needs `users.manage`. This page explains what each action does and the rules
that are enforced no matter how a request is made.

## The list

Search (name, email, username), filter (institution, role, active or
deactivated), sort (name, email, last active) and page. The filters live in the
address, so a filtered list can be bookmarked or shared. Tick people to act on
several at once (see Bulk).

## Creating a person: an invitation

Nobody is given a password. **Invite a person** asks for the institution, the
role, a name and an email; the person gets an emailed link, valid for 7 days and
usable once, and chooses their own password. The account is made when they do
(`docs/institution-onboarding.md` describes the link).

- The institution must have finished onboarding.
- A **student takes a seat**: the invitation is refused when the plan's seats
  are all in use, and again when the link is used if the seats filled up in the
  meantime. Other roles do not use seats.
- Inviting the same person again replaces their open invitation. A mistyped
  address can be cancelled from the institution's **Users** tab.
- **System administrators are not made here.** They are created only with
  `php spark admin:create`, and nothing on the screen can promote someone to the
  role or change a system administrator's roles.

## One person

`/admin/users/{id}` shows and changes:

| Action | What it does | Needs password again |
|---|---|---|
| Save details | Name and sign-in email. The email must not belong to another account. | yes |
| Deactivate | Switches the account off and ends its sessions. Nothing is deleted. | no |
| Reactivate | Switches it back on. A student needs a free seat. | no |
| Sign out everywhere | Ends every session. They can sign in again. | no |
| Send a password reset link | Emails a link; they choose the new password. | yes |
| Roles | Replaces their roles (at least one). | yes |
| Institutions | Adds or removes a membership. | yes |

Every change is recorded in the audit log (`user.updated`, `user.deactivated`,
`user.reactivated`, `user.signed_out`, `user.password_reset_sent`,
`user.roles_changed`, `user.membership_added`, `user.membership_removed`), in the
same transaction as the change.

### Rules

- You cannot deactivate **your own** account.
- The **last active system administrator** cannot be deactivated, so the platform
  is never left without one. In bulk, each is checked in turn, so selecting every
  administrator deactivates all but the last.
- A person always keeps at least one role.
- A system administrator belongs to no institution.
- A pending institution has no people: finish onboarding first.

## How deactivating really works

Shield only checks "banned" while someone is signing in and never looks again
once they are in, so changing a flag would not reach a person who already has a
session. Instead the `accountstate` filter runs on **every request** and looks
up one row in `user_account_controls`:

- `deactivated_at` is set: the person is signed out and sent to the login page
  with the reason, on their next click, whatever session they hold, and cannot
  stay in if they sign in again.
- `sessions_valid_from` is later than when their session began: that session is
  ended ("sign out everywhere", and a completed password reset). A session
  started afterwards is fine.

A person with no row is in good standing, so deploying this locks nobody out.
`users.active` is kept in step because the seat count reads it: a deactivated
student frees a seat.

## Password resets

An administrator never sees or chooses the password. **Send a password reset
link** emails the person a link (24 hours, usable once; the platform setting
`passwordResetValidityHours`). Using it sets the new password and **ends every
other session**, so anyone who had the old password is out. Sending another link
cancels the first. A deactivated account cannot be sent one, and a link sent
before an account was deactivated stops working.

The public page is `/reset-password/{secret}`; only a hash of the secret is
stored, like invitations.

## Bulk

Tick people, then **Deactivate**, **Reactivate** or **Export selected**. Each
person is checked on their own and the result says how many were done and why
the others were not (yourself, the last administrator, no seat). There is **no
bulk role change** on purpose.

## Export

**Export these** downloads everyone matching the current filters (up to 10,000);
**Export selected** downloads the ticked people. The file has name, email,
username, roles, institutions, status, last active and created. Cells that would
run as a spreadsheet formula (starting with `=`, `+`, `-`, `@`) are defused. Each
export is itself in the audit log (`users.exported`, with how many and how it was
narrowed).

## Viewing as someone (impersonation)

On a person's page, **View as this person** lets a system administrator see the
platform exactly as that person does, for support. It needs the
`impersonation.start` permission, the password again, and a **reason** (5 to
500 characters, kept in the audit log).

- **It is view only, enforced on the server.** While it lasts the session *is*
  the other person's, and every request except GET, HEAD and OPTIONS is refused
  (a script gets a 403 JSON error `impersonation_view_only`), so nothing can be
  saved on their behalf. Their saved role is not touched either.
- **It ends by itself** after the platform setting `impersonationMinutes`
  (30 by default). It also ends when the person's account is deactivated, when
  the administrator signs out (the administrator is put back, not signed out),
  or when the administrator's own account is switched off.
- **It cannot be missed:** an amber banner on every page shows who is being
  viewed, the reason, the minutes left and an **Exit view** button.
- **Limits:** you cannot view as yourself, as another system administrator, or
  as a deactivated account. The admin area is not reachable while viewing.
- **On the record:** `impersonation.started` and `impersonation.ended` (with how
  it ended and how long), and every entry written during the view carries the
  administrator as the *impersonator*.

Leaving: the **Exit view** button (`POST /impersonation/stop`), the time limit,
or signing out. The exit route sits outside the role areas on purpose, so that
the person's role does not stand in the way.

Known limit: the rule is by HTTP method, so a GET route that wrote something
would not be blocked. Changes in this app use POST, PATCH and DELETE; a new
route that writes on GET must not be added.

## Not here yet

- A person cannot reset their own password from the login page ("forgot
  password"): only an administrator can start a reset today.
- The password rules shown on the pages come from Shield's configuration
  (8 characters, not common, not containing the person's details); the
  platform-wide policy is a later branch.
