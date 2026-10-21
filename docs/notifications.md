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

## Not here yet

- Backup, service-down, sync-failed and repeated-failed-login notifications:
  those arrive with the monitoring, backup and SIS branches, using the same
  `NotificationService::send()`.
- Per-person preferences for anyone but a parent (everything urgent is emailed); a parent chooses per child.
