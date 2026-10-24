# Audit Plan 2 — One Institution per Account, Date-Based Status, Read-Only Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** An account belongs to exactly one institution (D1). An institution's access follows its subscription dates even when the nightly job hasn't run (6.1). A read-only institution's people can still change their own account things (finding 8).

**Architecture:**
- **One institution:** a unique key on `institution_users.user_id`, after a migration that keeps one membership per account. The features that existed only for second memberships are deleted.
- **Status:** one small class works out an institution's effective status, the worse of the stored status and what the subscription dates say. The places that decide access read it.
- **Read-only:** `ReadOnlyFilter` gets a short allow-list of own-account routes.

**Tech Stack:** CodeIgniter 4.7 + Shield, MySQL, PHPUnit 10 (`DatabaseTestCase`, `RouteTestCase`, `PeopleFactory`).

**Spec:** `docs/superpowers/specs/2026-10-23-audit-fixes-design.md`, sections F1, Finding 6 (6.1) and Finding 8. Decisions: `docs/audit-2026-10-23-decisions.md` 6.1, 8.1–8.3 and D1.

## Global Constraints

- **Branch and commits:** branch `fix/one-institution`, created from `fix/csrf` at bdcefb3. No commits by Claude: each commit step stages files and states the message, and the user commits. Messages end `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`.
- **Line endings:** `app/Config/Routes.php` and some tests use CRLF. Edit them with the Edit tool, never with `sed -i` or a Python rewrite.
- **One institution:** an account belongs to exactly one institution, and a system admin belongs to none (D1). Nothing in the app may create a second membership.
- **Effective status:** the worse of the stored status and the date status, on the ladder `active` < `read_only` < `past`. A `pending` institution is left alone. So is one with no subscription, which keeps its stored status. A manual suspension (stored `past`) is never lifted by dates.
- **Read-only allow-list (8.2):** personal reminders (`<area>/api/calendar/reminders…`), and the parent's email choices and password link (`parent/profile/notifications`, `parent/profile/password-link`). Notification read state (`notifications/read`) and role switch are already outside `readonly`. Nothing else is allowed.
- **Design language:** the admin user page's Institutions section and the institution People page keep their current look, minus the removed controls.
- **Known local failures:** the six known `AiWorkerEndpointsTest` failures stay (finding 14, plan 7). Run the full suite as `vendor/bin/phpunit --no-coverage`, because `composer test` times out after 300 s.

## Review Focus

- **Nightly job missing:** an institution whose subscription ended past its grace period, with the job not run, is refused writes, shown the read-only banner, refused a real SIS sync or grade send, and *allowed* the data export.
- **Manual suspension:** a suspended (`past`) institution whose dates say active stays past.
- **Renewal recorded without the job:** a renewal moves the end date forward and `statusAfterRenewal` sets the status, so the dates and the stored status both say active, and writing works.
- **Reminders in a read-only institution:** a student can still add, tick off and delete their own reminder, but can't post to a course.
- **Legacy data:** an account whose only membership was in a soft-deleted institution keeps that row rather than losing its last membership.

---

### Task 1: One membership per account in the database

**Files:**
- Create: `app/Database/Migrations/2026-11-13-000001_OneInstitutionPerAccount.php`
- Test: `tests/database/OneInstitutionPerAccountTest.php`

**Interfaces:**
- Produces: `OneInstitutionPerAccount::membershipsToDrop(list<array{id: int, user_id: int, deleted: bool}> $rows): list<int>`. It takes the rows of `institution_users` joined to `institutions.deleted_at`, and returns the membership ids to delete.
- Produces: unique key `institution_users_user_id_unique (user_id)`.

- [ ] **Step 1: Write the failing tests**

```php
public function testItKeepsTheOldestMembershipInAnInstitutionThatStillExists(): void
{
    $drop = OneInstitutionPerAccount::membershipsToDrop([
        ['id' => 5, 'user_id' => 1, 'deleted' => true],
        ['id' => 7, 'user_id' => 1, 'deleted' => false],
        ['id' => 9, 'user_id' => 1, 'deleted' => false],
        ['id' => 3, 'user_id' => 2, 'deleted' => false],
    ]);

    $this->assertSame([5, 9], $drop);
}

public function testAnAccountWhoseInstitutionsAreAllDeletedKeepsItsOldest(): void
{
    $this->assertSame([8], OneInstitutionPerAccount::membershipsToDrop([
        ['id' => 4, 'user_id' => 1, 'deleted' => true],
        ['id' => 8, 'user_id' => 1, 'deleted' => true],
    ]));
}

public function testASecondMembershipIsRefusedByTheDatabase(): void
{
    $a = PeopleFactory::institution('ONEA');
    $b = PeopleFactory::institution('ONEB');
    $user = PeopleFactory::person('student', institutionId: $a);

    $this->expectException(\CodeIgniter\Database\Exceptions\DatabaseException::class);

    db_connect()->table('institution_users')->insert(['institution_id' => $b, 'user_id' => $user->id, 'created_at' => date('Y-m-d H:i:s')]);
}
```

(`DatabaseTestCase` runs migrations; if it turns DB errors into `false` instead of exceptions, assert that `insert()` returns `false` and that the user still has one row.)

- [ ] **Step 2: Run them to verify they fail**

Run: `vendor/bin/phpunit --no-coverage tests/database/OneInstitutionPerAccountTest.php`
Expected: FAIL, because the class is not found.

- [ ] **Step 3: Implement the migration**

- `membershipsToDrop`: group the rows by user. Keep the lowest id among the not-deleted rows, or the lowest id overall when every row is deleted. Return the rest, sorted.
- `up()`: read the rows with a join, delete the returned ids, then add the unique key.
- `down()`: drop the key.

The class docblock says why: one account, one institution (decision D1). An account in a deleted institution keeps that row, because purge deals with it.

- [ ] **Step 4: Run the migration and the tests**

Run: `php spark migrate`, then `vendor/bin/phpunit --no-coverage tests/database/OneInstitutionPerAccountTest.php`
Expected: the migration applies, and the tests PASS (3 tests).

- [ ] **Step 5: Stage**

```bash
git add app/Database/Migrations/2026-11-13-000001_OneInstitutionPerAccount.php tests/database/OneInstitutionPerAccountTest.php
```
Message: `fix: an account belongs to one institution, kept by the database`

---

### Task 2: Remove what only existed for second memberships

**Files:**
- Modify: `app/Services/Users/UserAdminService.php:317-391`: delete `addMembership` and `removeMembership`.
- Modify: `app/Controllers/Admin/UsersController.php:169-179`: delete `addMembership` and `removeMembership`, and whatever only fed them (e.g. a `personInstitutions`/`memberOf` view variable used only by the add form).
- Modify: `app/Config/Routes.php:953-954`: delete both routes (Edit tool, CRLF).
- Modify: `app/Views/pages/admin/users/show.php:134-168`: the Institutions section lists the institution as a link, with no Remove button and no add form. Keep the empty-state texts.
- Modify: `app/Services/Institutions/PeopleService.php`:
  - delete `removeFromInstitution` (`:363-387`) and `accountIsOurs` (`:458-463`), with its callers;
  - delete the `elsewhere` flag (`:554`), and the filter at `:584` if it exists only to hide other memberships.
- Modify: `app/Controllers/Institution/PeopleController.php:121-124`: delete `remove`.
- Modify: `app/Config/Routes.php:850`: delete the `(:num)/remove` route (Edit tool).
- Modify: `app/Views/pages/institution/people.php:48-49, 66, 109-113`: delete the "Also at another institution" badge, its conditions and the "Remove from my institution" button.
- Modify: `app/Services/Admin/Impersonation.php:140-142, 271+`: delete the shared-account refusal and `institutionCount`, and drop the docblock sentence about accounts shared with another institution.
- Test: `tests/feature/OneInstitutionRoutesTest.php`, plus edits to the existing tests that exercised the removed code.

**Interfaces:**
- Consumes: Task 1's unique key, which is why these paths can be deleted.

- [ ] **Step 1: Write the failing tests** in `tests/feature/OneInstitutionRoutesTest.php` (`extends RouteTestCase`):

```php
public function testTheSystemAdministratorCannotAddOrRemoveAMembership(): void
{
    // signed in as a system admin (PeopleFactory::person('admin')), with reauth satisfied as the existing admin tests do
    foreach (["admin/users/{$this->person->id}/memberships", "admin/users/{$this->person->id}/memberships/{$this->institution}/remove"] as $uri) {
        try {
            $this->post($uri, ['institution_id' => $this->other]);
            $this->fail("{$uri} should not exist");
        } catch (\CodeIgniter\Exceptions\PageNotFoundException) {
        }
    }

    $this->assertSame(1, db_connect()->table('institution_users')->where('user_id', $this->person->id)->countAllResults());
}

public function testTheUserPageShowsTheInstitutionWithoutChangingIt(): void
{
    $html = (string) $this->get("admin/users/{$this->person->id}")->getBody();

    $this->assertStringContainsString(site_url("admin/institutions/{$this->institution}"), $html);
    $this->assertStringNotContainsString('/memberships', $html);
}

public function testAnInstitutionAdministratorHasNoRemoveFromMyInstitution(): void
{
    // signed in as the institution's admin
    $html = (string) $this->get('institution/people')->getBody();

    $this->assertStringNotContainsString('Remove from my institution', $html);
    $this->assertStringNotContainsString('data-elsewhere', $html);
}
```

- [ ] **Step 2: Run them to verify they fail**

Run: `vendor/bin/phpunit --no-coverage tests/feature/OneInstitutionRoutesTest.php`
Expected: FAIL, because the routes exist and the page has the add form.

- [ ] **Step 3: Delete the code listed under Files.**

- [ ] **Step 4: Run the new tests and the affected suites**

Run: `vendor/bin/phpunit --no-coverage tests/feature/OneInstitutionRoutesTest.php tests/database/UserAdminServiceTest.php tests/database/PeopleServiceTest.php tests/feature/AdminUsersPageTest.php tests/feature/InstitutionViewAsTest.php tests/feature/ImpersonationTest.php`
Expected: the new tests PASS. Tests of the removed behaviour fail (add/remove membership, remove from my institution, "also belongs elsewhere" refusals, view-as refused for a shared account). Delete those tests, and ledger the list. A test that only *used* two memberships incidentally is rewritten with one.

- [ ] **Step 5: Stage**

Stage every file changed in this task. Message: `fix: remove what only existed for accounts in two institutions`

---

### Task 3: Status follows the subscription dates

**Files:**
- Create: `app/Services/Subscriptions/EffectiveStatus.php`
- Modify: `app/Services/Sis/SisImporter.php:125`, `app/Services/Sis/SisGradeExporter.php:74`
- Modify: `app/Services/Institutions/InstitutionExport.php:71`, `app/Controllers/Institution/ExportController.php:38`
- Test: `tests/database/EffectiveStatusTest.php`

**Interfaces:**
- Consumes: `SubscriptionLifecycle::statusOf(string $endDate, int $institutionId, DateTimeImmutable $today): SubscriptionStatus` and `SubscriptionStatus::institutionStatus(): string` (existing).
- Produces:
  - `EffectiveStatus::combine(string $stored, ?string $endDate, int $institutionId, DateTimeImmutable $today): string` returns one of `pending|active|read_only|past`.
  - `EffectiveStatus::of(int $institutionId, ?DateTimeImmutable $today = null): string` reads `institutions.status` and `subscriptions.end_date`, then calls `combine`.

- [ ] **Step 1: Write the failing tests**

Fix today, and the institution's timeline, with the platform settings the existing `SubscriptionLifecycleTest` uses. Then cover:

```php
public static function provideCombinations(): iterable
{
    // stored, end date relative to today, expected
    yield 'dates say read-only, job never ran' => ['active', 'past grace, inside read-only', 'read_only'];
    yield 'dates say past'                       => ['active', 'past read-only period', 'past'];
    yield 'still in grace'                       => ['active', 'inside grace', 'active'];
    yield 'manual suspension is kept'            => ['past', 'far future', 'past'];
    yield 'already read-only, renewal not run'   => ['read_only', 'far future', 'read_only'];
    yield 'pending is left alone'                => ['pending', 'past read-only period', 'pending'];
}
```

Also test `testNoSubscriptionKeepsTheStoredStatus` (`combine('active', null, …)` gives `'active'`), and `testOfReadsTheInstitutionAndItsSubscription`: an institution stored `active` whose subscription ended 400 days ago gives `'past'`.

- [ ] **Step 2: Run them to verify they fail**

Run: `vendor/bin/phpunit --no-coverage tests/database/EffectiveStatusTest.php`
Expected: FAIL, because the class is not found.

- [ ] **Step 3: Implement `EffectiveStatus`**

`combine`: return `$stored` unchanged unless it is `active`, `read_only` or `past` and `$endDate` is not null. Otherwise take the higher-ranked of the stored and date statuses (the rank of `SubscriptionLifecycle::RANK`; make that constant public, or repeat the three-step ladder with a one-line comment). The docblock says why: access follows the dates even when the nightly `subscriptions:sync` hasn't run (decision 6.1). The job still records the change and its audit entry.

- [ ] **Step 4: Use it where access is decided**
  - `SisImporter.php:125` and `SisGradeExporter.php:74`: compare `EffectiveStatus::of($institutionId)` with `InstitutionModel::ACTIVE`.
  - `InstitutionExport.php:71` and `ExportController.php:38`: pass `EffectiveStatus::of(...)` to `isEligible`.
  - Add a test to `tests/database/SisImporterTest.php`: a stored-`active` institution past its read-only date is refused a real import with "Only an active institution can be synced." Add an export test: the same institution may take the export.

- [ ] **Step 5: Run the tests**

Run: `vendor/bin/phpunit --no-coverage tests/database/EffectiveStatusTest.php tests/database/SisImporterTest.php tests/database/SubscriptionLifecycleTest.php`, plus the SIS grade-export and institution-export test files (`grep -l "SisGradeExporter\|InstitutionExport" tests -r`)
Expected: PASS.

- [ ] **Step 6: Stage**

Message: `fix: an institution's access follows its subscription dates, even when the nightly job has not run`

---

### Task 4: Read-only follows the person's one institution, and leaves their own things open

**Files:**
- Modify: `app/Services/Institutions/InstitutionAccess.php:29-74`
- Modify: `app/Filters/ReadOnlyFilter.php`
- Modify: `tests/database/InstitutionAccessTest.php:80-90, 104+`, `tests/feature/ReadOnlyModeTest.php:28-48, 272-278`
- Test: `tests/feature/ReadOnlyOwnThingsTest.php`

**Interfaces:**
- Consumes: Task 3's `EffectiveStatus::of(int $institutionId): string`.
- Produces: `ReadOnlyFilter::OWN_ACCOUNT` (`list<string>`, path regexes):
  - `#^(student|instructor|chairperson)/api/calendar/reminders(/|$)#`
  - `#^parent/profile/(notifications|password-link)$#`

- [ ] **Step 1: Write the failing tests**

`ReadOnlyOwnThingsTest` (`extends RouteTestCase`) runs in an institution stored `read_only`, and again in one stored `past`:
- `testAStudentCanStillKeepTheirOwnReminders`: a POST to `student/api/calendar/reminders` with a title and date creates a row. A PATCH `…/{id}/done` and a DELETE `…/{id}` work too.
- `testAParentCanStillChooseTheirEmails`: a POST to `parent/profile/notifications` saves.
- `testACourseChangeIsStillRefused`: a POST to `student/api/courses/1/announcements/1/react` answers `institution_read_only`.
- `testTheDatesAloneMakeItReadOnly`: an institution stored `active` whose subscription ended past its grace period refuses a course change and shows the banner.

- [ ] **Step 2: Run them to verify they fail**

Run: `vendor/bin/phpunit --no-coverage tests/feature/ReadOnlyOwnThingsTest.php`
Expected: FAIL. Reminders and parent choices are refused, and the date-only institution is writable.

- [ ] **Step 3: Implement**
  - `InstitutionAccess::work`: `PlanLimits::institutionOf($userId)` (or the one row). No institution means `WRITABLE`. A deleted institution means `PAST`. Otherwise map `EffectiveStatus::of($id)`: `read_only` gives `READ_ONLY`, `past` gives `PAST`, anything else gives `WRITABLE`. Remove "several institutions" from the docblock.
  - `ReadOnlyFilter::before`: after the safe-method check, return `null` when the trimmed request path (`$request->getPath()`) matches any `OWN_ACCOUNT` pattern. Add a one-line comment: a person's own things stay theirs in a read-only institution (decision 8.2).

- [ ] **Step 4: Update the old tests for the new rules**
  - `ReadOnlyModeTest::writingRoutes()`: skip routes matching `ReadOnlyFilter::OWN_ACCOUNT`, since they are allowed now.
  - Delete `InstitutionAccessTest::testSomeoneInSeveralInstitutionsIsRestrictedOnlyIfNoneAllowsChanges`, which tests D1-impossible behaviour. Keep `testAnotherInstitutionsStateDoesNotLeak` and `ReadOnlyModeTest::testAnotherInstitutionsRestrictionDoesNotReachMe` if they use separate accounts; rewrite them with one membership each if not.

- [ ] **Step 5: Run the tests**

Run: `vendor/bin/phpunit --no-coverage tests/feature/ReadOnlyOwnThingsTest.php tests/feature/ReadOnlyModeTest.php tests/database/InstitutionAccessTest.php`
Expected: PASS.

- [ ] **Step 6: Stage**

Message: `fix: read-only follows the person's institution and its dates, and leaves their own reminders and settings open`

---

### Task 5: Full check

- [ ] **Step 1: Run every suite**

Run: `vendor/bin/phpunit --no-coverage`, then `npm test`, then `npm run build && npm run e2e`
Expected: PASS, apart from the six known `AiWorkerEndpointsTest` failures and the known Vitest calendar timeout under load (it passes when run alone). The demo seeders must still run under the e2e reseed. A seeder that links one account to two institutions fails here, and gets one membership instead (ledger it).

- [ ] **Step 2: Docs**

In `docs/institutional-admin-area.md`, remove any mention of "remove from my institution" or of people who also belong elsewhere (`grep -n -i "elsewhere\|another institution\|remove from" docs/*.md`), and the same for the system admin's user page in its doc. Stage them, and hand over the `git add` list with the task messages.
