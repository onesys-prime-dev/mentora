# Messages and the single-identity track: coordination note

Date: 2026-11-27 · From: the institution-wide Messages track (Phase 7, branch `feat/messages-open-p7-reach`, not yet merged to main) · For: the single-identity track (`docs/feature-development/single-identity/`, on hold).

Kept here, not in the single-identity folder, because that folder is the other track's own work (Ruling R12). This is the note promised in `open-questions.md` and `requirements.md` §8.

## 1. Messages no longer reads the active role

Messages code does not read `active_role`, `ActiveRoleService`, `usableGroups(` or `preferredRole(`. This covers `app/Services/Messaging`, `app/Controllers/Messaging`, `app/Filters/MessagesFilter.php`, `app/Views/pages/messages` and `resources/js/pages/messages`. `tests/unit/NoActiveRoleInMessagesTest.php` fails the build if any of them comes back (AC3).

**The single-identity inventory can drop Messages.** Switching or removing the active role changes nothing in Messages.

## 2. How Messages decides instead: parties, every role held (D4)

- A person is a `Party` (`app/Services/Messaging/Party.php`). It carries every role the account holds, its institution, whether the account is active, and the minor flag. `MessagingContextResolver::parties()` builds parties in bulk.
- **Roles** come from Shield's `auth_groups_users`, with two adjustments:
  - A `dean` with no active dean assignment loses `dean` while they hold another role (`usableRoles()`). This matches the auth module's usable-groups rule, and the two must stay in step.
  - Beside any institution role, `admin` is ignored. Only an account whose sole role is `admin` counts as the system administrator, who has no Messages at all (Ruling R10, `Party::isSystemAdmin()`, `MessagesFilter`).
- **Institution:** `PlanLimits::institutionOf` / `institutionsOf`, meaning the person's first institution (D10).
- **The thread picks the capacity, not the session:**
  - A thread that names a child is a **parent** thread. One side must hold `parent` and be linked to the child. The other side must hold a staff role.
  - Any other thread uses **staff** if the person holds any of instructor, chairperson, dean or institutional_admin, else **student**.
  - A parent-only account can act only in parent threads.
- **Other rules that read the role set:**
  - The minor rule (D9) applies even when the minor also holds a staff role.
  - Thread limits use the staff numbers when any staff role is held.
  - Block exceptions and the pause exemption read the role set.
  - Group creation is open to anyone but a parent-only account.
- Every rule is in `MessagingPolicy` (pure). Facts come from `MessagingContextResolver`.

## 3. What single identity must keep, or tell Messages about

1. **The role set stays the source of truth.** Messages needs every role an account holds, read from `auth_groups_users`, with the two adjustments above. If single identity changes where roles live, it must change `MessagingContextResolver::roles()`/`parties()` at the same time. One account must keep all its roles: Messages relies on a staff+parent or student+instructor account being a single user id.
2. **One user id per person.** Threads, participants, blocks, reports, restrictions, the pause and preferences are all keyed by `users.id`. Merging two accounts would need a migration plan for these tables: `conversations.direct_key` is a hash of the pair (plus child), so a merge can collide or duplicate threads. Ask before merging.
3. **The usable-groups rule for deans:** `usableRoles()` copies the auth module's rule. Keep them in step, or have Messages call the shared rule once single identity owns it.
4. **The system administrator:** an account holding only `admin` must stay recognisable as such. Messages refuses it everywhere.
5. **Institution of a person:** Messages relies on `PlanLimits::institutionOf` (first institution, D10). A change to multi-institution identity needs a Messages decision first; cross-institution messaging is a non-goal.
6. **Impersonation:** `Impersonation::active()` still blocks Messages entirely. Keep that signal available.
7. **The profile Message button** (UP D20/D32) now follows `MessagingPolicy::canStart` with no active-role exception (S2-a). Any profile change single identity makes must keep calling the same check.

## 4. Nothing for single identity to change in Messages

The track needs no Messages code change unless one of the points in §3 moves. Questions go to the Messages track's `open-questions.md`.
