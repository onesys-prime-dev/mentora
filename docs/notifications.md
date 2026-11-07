# Notifications

People are told things in the app (`/notifications`, for every role) and, when
it is urgent, by email as well.

## Who is told what

| Event | Told | Urgent (also emailed) |
|---|---|---|
| Subscription ends within the warning window | system administrators, the institution's administrators | no |
| Grace period started | same | no |
| Read-only started | same | **yes** |
| Subscription expired (institution is past) | same | **yes** |
| A limit (seats, storage, AI) reaches 90% | same | no |
| A limit is used up (100%) | same | **yes** |

Deactivated and deleted accounts are never told. System administrators hear
about every institution and get a link to its subscription page; an
institution's administrators hear about their own institution.

## How it runs

`php spark subscriptions:sync` (already scheduled daily) works out the above
after moving institutions along their timeline. It tells whoever has not yet
been told, so it is safe to run as often as you like: each person is told each
thing once (a unique key on person + what happened). A new subscription end
date after a renewal counts as a new thing. `--dry-run` creates nothing.

A failed email never loses the in-app notification; the notification just has
no `emailed_at`.

## Where people see them

- `/notifications`: the list, newest first, with **Mark as read** and **Mark all
  as read**. A person only ever sees their own.
- System administrators see an unread count next to "Notifications" in the
  admin header; everyone else sees a notice line under the page header while
  something is unread.

## Emails to people who are added or changed

People are told when an upload, an import, an enrolment or an assignment adds them or changes their courses and roles
(`MailOutbox`). A new student or instructor has no password, so the first email is how they set one.

| What happened | Told | Emailed |
|---|---|---|
| A new account was made by an upload or an SIS import | the person | **a link to choose a password** (works once, as long as an invitation link: `invitationValidityDays`), their sign-in email, and what they were given |
| A student got a place in a course (enrolled by hand, in a section, by upload or import; a dropped place that is reinstated) | the student | yes, and in the app |
| An instructor was assigned to a section (by hand, upload or import) | the instructor | yes, and in the app |
| Someone was made dean or chairperson (by hand, upload or import) | the person | yes, and in the app |
| A place was dropped or withdrawn, an assignment ended, a name or an email corrected | nobody | no |

How it works:

- The change writes a row to `mail_outbox` **in the same transaction**, so a preview, a refused change or a run that rolled back
  tells nobody. Nothing is sent inside the request.
- `php spark exports:run` (every minute) sends what is due: **one email per person per institution**, made from all their
  rows. A row waits two minutes first, so changes made one after another make one email. At most 50 people per run; the
  rest wait for the next. A failed send is tried again after 15 minutes, up to 5 times, and never loses the change.
- The welcome link is made when the email is sent, so its time starts then. If it expires, send a new one from People.
- Deactivated, switched-off and deleted accounts are never emailed; their rows are closed with the reason in `last_error`.
- **An upload** can be made quiet with the box on its first step. **The first real import from an institution's SIS** emails
  nobody (it can add a whole school at once): send the first password links from People. Later syncs email as above.
- No email carries a grade, a percentage or a score.

## Parents

A child's linked parents are told what changes for the child (`ParentNotifier`). Each parent hears each
thing once, in the app always, and by email when the event is one of the emailed kinds and their switch
for that child is on (default on; set on their Profile page, `parent/profile`).

| Event | Told when | Emailed | Opens |
|---|---|---|---|
| Final grade available | the instructor finalizes the grades (also found by the sweep, if finalized in the last 14 days) | yes | Grades tab |
| Marked incomplete (INC) | the sweep finds it | yes | Grades tab |
| Withdrawn | the sweep finds it | yes | Overview tab |
| May be falling behind ("At risk") | the sweep finds the running grade below the passing mark | yes | Grades tab |
| Work not handed in | the sweep: **one message per child per day**, saying only how many | yes (that one) | Assessments tab, or the dashboard |
| New course announcement | the sweep, for announcements of the last 3 days | **never** | Announcements tab |

Rules: only a child's **current** courses count (an active offering, an enrolled student, a published
grading scheme); nothing is told while the institution's parent portal is switched off; a deactivated
or deleted parent is never told; **no message or email carries a grade, a percentage or a score**, only
that something is ready and where to sign in. INC, Withdrawn and At risk are told once per child and
course; a final grade once per finalization (so reopening and finalizing again tells again); an item not
handed in is reported once, ever (`parent_alerted_items`).

The sweep is `php spark parents:notify` (add `--dry-run` to count without telling anyone). **It must be
scheduled every 15 minutes** (see `docs/server-setup.md`, section 6); until it is, only final grades are
sent (those go at once). Running it more often is harmless.

## Messages

A new message tells the people in the conversation (not the sender, and not anyone who muted it) with
one notification per conversation: "{name} sent you a message in {chat}", linking to it. While someone
still has that notification unread, a newer message does not make another. It **never contains what was
written**. It is emailed when the person's email switch for Messages is on, at most once an hour per
conversation, using the same words. Instructors, chairpersons and deans can set quiet hours; nothing is
notified or emailed during them, and the notification is made once they end. A send tells the other
members at once only in conversations of 30 or fewer people; `php spark messages:notify` (every 15
minutes) tells the rest and releases what quiet hours held. A failed email never loses the in-app
notification. See `messages.md`.

## Not here yet

- Backup, service-down, sync-failed and repeated-failed-login notifications:
  those arrive with the monitoring, backup and SIS branches, using the same
  `NotificationService::send()`.
- Per-person preferences for anyone but a parent (everything urgent is emailed); a parent chooses per child.
