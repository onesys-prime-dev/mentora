# Onboarding an institution

A new customer is set up in one guided flow by a system administrator. The
goal: nothing in an institution can be used until its plan is chosen and its
first administrator has been invited.

## The three steps

Start at **Institutions > Add an institution** (`/admin/institutions/new`).

| Step | Page | What happens |
|---|---|---|
| 1. Institution | name and code | The institution is created as **pending**. Its code never changes. |
| 2. Plan and subscription | plan, period, reference-only payment details | Checked, **not saved**. Kept in the administrator's session so they can go back and change it. |
| 3. Administrator | first name, last name, email | **Finish onboarding** saves everything at once (below). |

Leaving halfway is safe. The institution stays `pending`: no subscription, no
invitation, no members, so there is nothing to sign in to. It is listed with a
**Finish onboarding** button (and **Continue onboarding** on its profile) and
the flow picks up where it was left. An institution created before onboarding
existed and still `pending` is finished the same way.

## What "Finish onboarding" does

In **one database transaction**, so that all of it happens or none of it does:

1. starts the subscription (and its first history row),
2. writes the invitation for the administrator,
3. moves the institution from `pending` to the status the subscription's dates
   call for (`active` for any subscription that has not ended),
4. writes the audit entries (`subscription.started`, `invitation.created`,
   `institution.onboarded`).

The invitation **email is sent after that is saved**, because an email cannot
be taken back. If the email fails the institution is still onboarded; the page
says the email did not go and the invitation shows as **Not sent** on the
institution's **Users** tab with a **Resend invitation** button.

A subscription that has already ended is refused: a new customer cannot start
expired.

## The invitation

- The administrator gets **no account yet**. They get an emailed link
  (`/invitation/<secret>`), choose their own password on it, and the account is
  made then, as an *institutional administrator* of that institution.
- The link is **single-use** and **expires** (7 days by default; the platform
  setting `invitationValidityDays`, 1 to 60).
- The secret is 256 random bits. Only its SHA-256 hash is stored, so nobody
  reading the database can accept an invitation, and it never appears in the
  audit log.
- **One open invitation per institution.** Sending another (to a corrected
  address, or a re-send) revokes the open one, so a link mailed to a mistyped
  address cannot be used later.
- The address must not already belong to an account.
- A password that is refused (too short, too common, contains the person's name
  or email) leaves the link usable; only a successful use consumes it.
- After setting the password the person is sent to the sign-in page. They are
  not signed in automatically.
- A link for a deleted institution, or one that is used, replaced, expired or
  unknown, shows a plain explanation (HTTP 410, or 404 for an unknown link).

### Sending again

On **Users** (`/admin/institutions/{id}/users`): **Resend invitation** gives
the same person a fresh link and retires the old one; **Invite an
administrator** invites someone else (or a corrected address). Both are
audit-logged (`invitation.resent`, `invitation.created`). An accepted
invitation cannot be re-sent.

## The institution's pages

An institution now has a profile and tabs, each its own page:

| Tab | Page |
|---|---|
| Profile | `/admin/institutions/{id}`: details, subscription summary, administrator invitation, onboarding status |
| Subscription | `/admin/institutions/{id}/subscription`: plan, usage against limits, renewals and history |
| Users | `/admin/institutions/{id}/users`: people with their roles, and invitations |
| Features | `/admin/institutions/{id}/flags` |
| Audit | `/admin/institutions/{id}/audit`: the latest entries, with a link to the full log filtered to this institution |

The plan says "usage vs limits" is its own tab; it is shown on the Subscription
tab. An Integrations tab arrives with the SIS connector.

A pending institution's Subscription page sends the administrator to
onboarding instead of offering a separate "Start a subscription" form, so the
administrator is never left out.

## When something goes wrong

| Symptom | Meaning | What to do |
|---|---|---|
| "The invitation was saved, but the email could not be sent" | The mail server refused or could not be reached | Fix the mail settings (`docs/mail.md`, `php spark mail:test`), then **Resend invitation** |
| The administrator says the link "has expired" | More than the validity period passed | **Resend invitation** |
| The administrator says the link "was replaced" | A newer invitation was sent | They should use the link in the latest email |
| Onboarding says "an account with that email already exists" | The address is already used | Invite a different address |
| In development, no email arrives | There is no mail server on a laptop | The link is written to `writable/logs` (search for "invitation link"); development only |

## Not built yet

- There is no institutional-administrator area. Until it exists, an invited
  administrator can set a password and sign in, but sees "Your account does not
  have a role that can use this site yet."
- The seat limit is not enforced when accounts are created, because no screen
  creates students yet (see the plan limits notes).
