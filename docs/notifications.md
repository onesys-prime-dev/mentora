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

## Not here yet

- Backup, service-down, sync-failed and repeated-failed-login notifications:
  those arrive with the monitoring, backup and SIS branches, using the same
  `NotificationService::send()`.
- Per-person preferences (everything urgent is emailed).
