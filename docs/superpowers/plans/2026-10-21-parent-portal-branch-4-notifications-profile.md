# Parent Portal, Branch 4: Notifications and the Parent's Profile Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Every linked parent is told, in the app and (where they allow it) by email, when something changes for their child, and has a profile page with their account, the per-child email switches and a way to change their password.

**Architecture:** A `ParentNotifier` service works out who to tell and what, and tells them through the existing idempotent `NotificationService::send()` (`urgent` = also email). The final-grade event is sent at once from `GradeFinalizationService::finalize`; every event is also found by a scheduled sweep (`php spark parents:notify`, meant every 15 minutes), so a missed hook or a state the instructor changes elsewhere is never lost and nobody is told twice. Per-child email choices live in a small table. The profile page is the parent's own account page.

**Tech Stack:** CodeIgniter 4 (PHP 8.2), PHPUnit 10 (`DatabaseTestCase`, `RouteTestCase`), the existing `NotificationService`, `PasswordResetService`, `FakeEmail`-style test email.

**Spec:** Appendix A of `2026-10-21-parent-portal-branch-1-access.md` (Q7, Q10, Q17, Q19, Q21), branches 1 to 3 on `feat/parent-access`, `feat/parent-dashboard`, `feat/parent-course-view` (this branch is stacked on them).

## Global Constraints

- Branch `feat/parent-notifications`, stacked on `feat/parent-course-view`. **No commits** (the user reviews and commits). Branch 5 is not started without approval.
- **Who is told:** every parent linked to the child, once per event (a unique key per parent and event), only while the institution's parent portal is on, and never a deactivated or deleted parent. Only a child's **current** courses (an active offering, an enrolled student) are watched.
- **Events:** final grade finalized; INC; Withdrawn; At risk (the gradebook's running grade below the passing mark, the same word the student sees); new work not handed in; a course announcement.
- **Email:** in-app is always on. Email goes with the first five events when the parent's switch for that child is on (default on); a course announcement is **never** emailed. A failed email never loses the in-app notification.
- **Email and notification text never contains a grade, a percentage or a score** (see the copy below). The details stay behind the sign-in.
- **Missing work is one notification per child per day** (also the one email), covering what became overdue since the last one; never one per item.
- The parent group's rule "nothing a parent does changes a child's data" stands. Two POST routes are added, both **the parent's own account only**: saving their email switches and sending themselves a password link. A read-only institution refuses both (the `readonly` filter).
- UI: shared components and tokens only (`docs/ui-guide.md`); works at 360 px; **wireframe L and the copy table below are approved before any view or message is written** (Tasks 2 and 6).
- No new `phpstan-baseline.neon` entries; test class names unique across `tests/unit`, `tests/feature`, `tests/database`; every new service has a test class named after it (`ServiceTestInventoryTest`), every new table a test (`TableTestInventoryTest`).

## Review Focus

- The first sweep after deploy must not flood: old finals (> 14 days), ended courses and dropped students produce nothing.
- Running the sweep twice, or the hook and then the sweep, tells nobody twice. A reopened and re-finalized course tells again (a new finalization).
- A parent who turned a child's email off still gets the in-app notification; an announcement never emails.
- A parent whose institution switched the portal off, a deactivated parent, an unlinked parent and a parent of another child get nothing.
- Two parents of one child both hear; one parent with two children in the same course hears an announcement once.
- An excused assessment, a submitted one, a draft or an unpublished one is never "not handed in"; an item is reported once, ever.
- Texts: no digit followed by `%`, no "grade of", no score, in any notification or email.
- The profile's password link goes only to the signed-in parent's own address, and a parent cannot change another parent's switches or see a child they are not linked to.

---

## Decisions to confirm with the wireframe and the copy

1. **Password (needs your decision).** The product has **no self-service password change for any role** (only administrator-started emailed links; "My Profile" in the menu is an inert item). Q19 asked the profile for "password change". Recommended: a button **"Email me a link to change my password"** that uses the existing `PasswordResetService` (a one-time, hashed, expiring link to the parent's own address, audited). The alternative is a new current-password form, a new security-sensitive surface no other role has.
2. **"At once".** A final grade is sent at the moment it is finalized. INC, Withdrawn, At risk, missing work and announcements are found by the sweep, so they arrive within one run (recommended: every 15 minutes). INC and Withdrawn are changed in several places by instructors and the enrolment code, so they are not hooked one by one.
3. **A server action for you.** Neither server has any cron entries today (`docs/server-setup.md` section 6). The sweep needs one line on each: `*/15 * * * * cd <site folder> && php8.5 spark parents:notify >> writable/logs/cron-parents.log 2>&1`. Nothing is sent until it is added (except final grades, which are sent at once).
4. **Told once.** At risk and INC/Withdrawn are told once per child and course (not again if the state returns); a final grade once per finalization.

## Wireframe L: the parent's Profile (`parent/profile`)

```
  < Back to dashboard
  (icon) Profile                      Your account and how Mentora tells you things.
  +--------------------------------------------------------------+
  | Your account                                                 |
  |  Name      Pam Parent                                        |
  |  Email     pam@example.com                                   |
  |            To change your email, contact your school.        |
  |  Password  [ Email me a link to change my password ]         |
  +--------------------------------------------------------------+
  +--------------------------------------------------------------+
  | Your children                                                |
  |  Ann Lee      [x] Email me about Ann                         |
  |  Ben Lee      [ ] Email me about Ben                         |
  |  Notifications in the app are always on.                     |
  |  [empty: "You are not linked to a student yet."]             |
  |                                           [ Save choices ]   |
  +--------------------------------------------------------------+
```
Saving shows a toast ("Your email choices were saved"); the password button shows "We emailed a link to pam@example.com". With the portal switched off the "Your children" card says so and the form is gone; the account card stays.

## Copy (what a parent reads; `{First}` is the child's first name, `{code}` the course code)

| Event | Title | Body | Emailed | Opens |
|---|---|---|---|---|
| Final grade | Final grade available for {First} | {First}'s final grade for {code} is ready. Sign in to view it. | yes | Grades tab |
| INC | {First} is marked incomplete in {code} | The instructor marked {First}'s work in {code} as incomplete (INC). Sign in for details. | yes | Grades tab |
| Withdrawn | {First} is withdrawn from {code} | {First} is shown as withdrawn from {code}. Contact the school if this is a surprise. | yes | Overview tab |
| At risk | {First} may be falling behind in {code} | {First}'s grade so far in {code} is below the passing mark. Sign in to see how it is made up. | yes | Grades tab |
| Not handed in | {First} has work not handed in | {First} has {n} new {item/items} not handed in. Sign in to see which. | yes (once a day) | Assessments tab of the course (or the dashboard when several courses) |
| Announcement | New announcement in {code} | The instructor posted "{subject}" in {code}. | never | Announcements tab |

---

## File Structure

- Create `app/Database/Migrations/2026-11-08-000001_CreateParentNotificationTables.php` (`parent_child_preferences`, `parent_alerted_items`).
- Create `app/Services/Parents/ParentPreferences.php`, `app/Services/Parents/ParentNotifier.php`, `app/Commands/ParentsNotify.php`, `app/Controllers/Parents/ParentProfileController.php`, `app/Views/pages/parent/profile.php`.
- Modify `app/Services/Courses/Grades/GradeFinalizationService.php` (the at-once hook), `app/Config/Routes.php`, `app/Helpers/navigation_helper.php`.
- Tests: `tests/database/{ParentPreferencesTest,ParentNotifierTest,ParentNotificationTablesTest}.php`, `tests/feature/{ParentProfilePageTest,ParentNotificationsPageTest}.php`, `tests/unit/ParentsNotifyCommandTest.php` (or database), extend `NavigationHelperTest`, `RouteAccessTest`, `GradeFinalizationServiceTest`.
- Docs: `docs/notifications.md`, `docs/server-setup.md` (the cron line), `docs/course-access.md`.

---

### Task 1: The tables and the per-child email choice

**Files:** Create the migration, `ParentPreferences.php`; Test `tests/database/ParentNotificationTablesTest.php`, `tests/database/ParentPreferencesTest.php`.

**Interfaces:**
- Produces: tables `parent_child_preferences` (`parent_user_id`, `student_user_id`, `email_enabled` TINYINT default 1, unique on the pair) and `parent_alerted_items` (`student_user_id`, `kind` VARCHAR(20), `ref_id`, unique on the three).
- Produces: `ParentPreferences::emailEnabled(int $parentId, int $studentId): bool` (no row = true); `ParentPreferences::save(int $parentId, array $emailByChild): void` where `$emailByChild` is `student_id => bool` for **linked** children only (unknown ids are ignored).

- [ ] **Step 1: Write failing tests:** both tables exist after migrating, with their unique keys (a second row for the same key is refused); `emailEnabled` is true by default, false after `save` with false, true again after true; `save` ignores a student the parent is not linked to; one parent's choice never changes another parent's.
- [ ] **Step 2: Run** `vendor/bin/phpunit tests/database/ParentNotificationTablesTest.php tests/database/ParentPreferencesTest.php` → FAIL.
- [ ] **Step 3: Implement** the migration (reversible `down`) and the service.
- [ ] **Step 4: Run** the two files plus `TableTestInventoryTest` → PASS.

### Task 2: `ParentNotifier`: who is told, and the final-grade event

**Gate:** the copy table is approved before the messages are written.

**Files:** Create `ParentNotifier.php`; Modify `GradeFinalizationService.php`; Test `tests/database/ParentNotifierTest.php`, extend `GradeFinalizationServiceTest.php`.

**Interfaces:**
- Produces: `ParentNotifier::__construct(?NotificationService $notes = null, ?ParentPreferences $prefs = null, ?ParentPortal $portal = null)`.
- Produces: `ParentNotifier::parentsOf(int $studentId): list<array{parent_id: int, institution_id: int}>`: linked parents (the link's institution), active, not deleted, holding the `parent` role, whose institution's portal is on, and where the student still belongs to that institution.
- Produces: `ParentNotifier::finalGrades(?int $offeringId = null, ?DateTimeImmutable $now = null): int`: for each record of a `Passed` or `Failed` grade finalized within the last 14 days in an active offering (one offering, or all), tells the child's parents; key `parent.final:{student}:{offering}:{finalized_at}`; returns how many notifications were created. Urgent (emailed) exactly when that parent's switch for that child is on.
- Produces: `GradeFinalizationService::finalize` calls `ParentNotifier::finalGrades($offeringId)` after the sign-off announcement, inside a try/catch that logs and never blocks the finalization.

- [ ] **Step 1: Write failing tests** (`NotificationService` is real; email is the test mailer): a finalized course tells both parents of the child once, in the app, with the copy's title/body and a link to the Grades tab; with the switch off, in-app only (`emailed_at` empty, `urgent` 0) and with it on, emailed; an `INC` or `Withdrawn` finalization sends no final-grade message; a finalization older than 14 days, an ended offering, a dropped student send nothing; a second call and a call after the hook send nothing more; reopening and re-finalizing tells again; a deactivated parent, an unlinked parent, a parent in a portal-off institution and the child's other parent's switch are respected; the body and title contain no `%` and no digit-percentage; `GradeFinalizationService::finalize` creates the notification (extend its test) and still succeeds when the notifier throws.
- [ ] **Step 2: Run** → FAIL. **Step 3: Implement. Step 4: Run** the two test files plus `GradeFinalizationServiceTest` → PASS.

### Task 3: INC, Withdrawn, At risk and announcements

**Files:** extend `ParentNotifier.php`; extend `ParentNotifierTest.php`.

**Interfaces:**
- Produces: `ParentNotifier::standings(?DateTimeImmutable $now = null): int`: for each gradebook record of a current course (active offering, enrolled student, **published** scheme): `INC` → INC; `Withdrawn` → Withdrawn; `Failed` with no finalization → At risk. Keys `parent.inc|withdrawn|at_risk:{student}:{offering}` (once each); all emailed when the switch is on.
- Produces: `ParentNotifier::announcements(?DateTimeImmutable $now = null): int`: each announcement created in the last 3 days in an active offering tells each parent of each enrolled student once (key `parent.announcement:{announcement}`, in-app only, never `urgent`), linking to the Announcements tab of the first of their children in that course.

- [ ] **Step 1: Write failing tests:** each of the four events tells the right parents once with the copy; running again tells nobody; a draft scheme, a finalized `Failed` (that is a final grade, not "at risk"), an ended course, a dropped student produce nothing; INC/Withdrawn win over At risk; an announcement older than 3 days or in another course produces nothing; a parent with two children in the course hears one announcement notification; an announcement is never emailed even with the switch on; the switch off still gives the in-app one for the other events; no text contains a grade.
- [ ] **Step 2: Run** → FAIL. **Step 3: Implement. Step 4: Run** → PASS.

### Task 4: New work not handed in (one summary a day)

**Files:** extend `ParentNotifier.php`; extend `ParentNotifierTest.php`.

**Interfaces:**
- Produces: `ParentNotifier::missingWork(?DateTimeImmutable $now = null): int`: per child with new items, **one** notification per parent for that day (key `parent.missing:{student}:{Y-m-d}`) naming only how many; "new" = an assessment that is Published, visible to the student (module published; its learning item and unit published when it has one), due within the last 7 days and already past, with no submission other than "In Progress"/"Cancelled", not excused (a gradebook item behind it with `is_missing`), in a current course, and not yet in `parent_alerted_items` (kind `missing`). The items are recorded as alerted only when the day's notification was actually created, so an item that turns overdue after the day's summary is told tomorrow.

- [ ] **Step 1: Write failing tests:** two overdue items → one notification saying 2; running again the same day → nothing more; an item that becomes overdue later the same day → in the next day's summary only; a handed-in, an excused, a draft, a future-due, an older-than-7-days and an ended-course item → nothing; two parents → both told, both emailed (when on) with the same single message; the message lacks any grade.
- [ ] **Step 2: Run** → FAIL. **Step 3: Implement. Step 4: Run** → PASS.

### Task 5: The sweep command and the schedule

**Files:** Create `app/Commands/ParentsNotify.php`; Modify `docs/server-setup.md`, `docs/notifications.md`; Test `tests/database/ParentsNotifyCommandTest.php`.

**Interfaces:**
- Produces: `ParentNotifier::sweep(?DateTimeImmutable $now = null): array{final: int, standing: int, announcement: int, missing: int}` running the four events; `php spark parents:notify [--dry-run]`: prints the counts, exits non-zero if the sweep throws, and records `Monitoring.parentsNotifyAt`. `--dry-run` creates nothing (counts what would be told, using a read-only path or a rolled-back transaction).

- [ ] **Step 1: Write failing tests:** `sweep` returns the four counts and tells each person once on a second run; `--dry-run` creates no notification, no `parent_alerted_items` row and no email; the command exits success and reports the counts; the first run on a database with old finals/ended courses creates nothing for them.
- [ ] **Step 2: Run** → FAIL. **Step 3: Implement** and add the cron line to the docs (with the "neither server has cron yet" note). **Step 4: Run** → PASS.

### Task 6: The Profile page, the menu and the notification list (UI: wireframe L)

**Gate:** wireframe L and decision 1 are settled before this task.

**Files:** Create `ParentProfileController.php`, `app/Views/pages/parent/profile.php`; Modify `Routes.php`, `navigation_helper.php`; Test `tests/feature/ParentProfilePageTest.php`, `tests/feature/ParentNotificationsPageTest.php`, extend `NavigationHelperTest.php`, `RouteAccessTest.php`.

**Interfaces:**
- Produces routes in the `parent` group: `GET profile` → `ParentProfileController::index`; `POST profile/notifications` → `::saveNotifications` (filter `parentportal`; body `email[]` = the child ids switched on, absent ones are off); `POST profile/password-link` → `::sendPasswordLink` (calls `PasswordResetService::send(auth id, auth user)`, answers with a flash message; a failed email says so and the link can be sent again).
- Consumes: `ParentAccessService::children`, `ParentPreferences`, `PasswordResetService`.
- Menu: a parent's tiles become Home, Calendar, Notifications (`notifications`), Profile (`parent/profile`).

- [ ] **Step 1: Write failing tests:** the page shows name, email (read-only text, no input), the password button, each child with a checkbox checked by default and unchecked after being saved off; saving stores exactly the ticked children and ignores an id the parent is not linked to (and a student of another institution); one parent saving never changes another's; the password button sends one reset to the parent's own address (the test mailer has it, `password_resets` has a row for the parent only) and a second press replaces the first; the portal switched off: the account card shows and the children card says so, `POST profile/notifications` is refused; a parent with no children sees the plain message; a read-only institution refuses both POSTs; other roles are turned away; the only POST routes in the `parent` group are these two (a route-table assertion); the menu test lists four tiles that are registered GET routes; a parent signed in sees their parent notifications on `/notifications` and the link opens the right parent page.
- [ ] **Step 2: Run** → FAIL. **Step 3: Implement** (form-field/button styles from the other settings forms; checkboxes as in the security tab). **Step 4: Run** → PASS.

### Task 7: Docs and verification

**Files:** Modify `docs/notifications.md`, `docs/course-access.md`.

- [ ] **Step 1:** document the events, who is told, the copy rule, the schedule and the profile in `docs/notifications.md` (the "Per-person preferences" line under "Not here yet" is replaced for parents) and a line in `docs/course-access.md`.
- [ ] **Step 2: Verify:** the new and touched PHPUnit files; the whole suite (`vendor/bin/phpunit`), reading failures by name (the six `AiWorkerEndpointsTest` ones are the known worker-secret failures); `vendor/bin/phpstan analyse`; `npx eslint resources/js tests/js`; `npx vitest run`. Report real output.

---

## Self-review

- **Spec coverage:** Q7 (events, in-app always, email for the first five, per-child switch) → Tasks 1 to 4; Q17 (no grades in text, daily batch for missing work, per-child switch on the profile, immediate finals/INC/Withdrawn) → Tasks 2 to 4 and the schedule (INC/Withdrawn at the next sweep, flagged as decision 2); Q19 (profile: name, read-only email, password, per-child switches, linked children) → Task 6 (password as decision 1); Q21 (Notifications and Profile tiles) → Task 6; Q10's audit of the password link comes from `PasswordResetService`. The student's "Linked parents" list and the invite step are branch 5.
- **Placeholder scan:** none; each step names tests and signatures. Decision 1 gates Task 6, the copy gates Task 2.
- **Type consistency:** `ParentNotifier` methods all take `?DateTimeImmutable $now` and return how many notifications were created; `sweep` sums them by event name.
- **Proportion:** seven tasks, two small services, one command, one page.
