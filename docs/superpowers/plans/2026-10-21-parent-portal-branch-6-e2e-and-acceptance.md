# Parent Portal Branch 6 — Acceptance Tests, Demo Data and End-to-End Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans (inline, TDD) to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax.

**Goal:** Prove the parent portal's definition of done (Q23) with an authorization matrix, a privacy-canary sweep, a demo seed that exercises every parent state, Playwright journeys and a test-case catalog — without changing any application behaviour.

**Architecture:** No new UI, so no wireframes. Tests only, plus one seeder (`DemoParentLinksSeeder`) that links demo parents to demo students after enrolments exist. The PHPUnit matrix reads the route table so a new parent route cannot ship untested; the canary sweep builds one rich fixture and requests every parent GET.

**Tech Stack:** PHPUnit 10 (`DatabaseTestCase`, `RouteTestCase`, factories), Playwright (`mentora_e2e`), Vitest only if a JS helper turns out to need it, docs in `docs/test-cases`.

**Spec:** the grill-me agreement (Q1–Q23) summarised in `docs/superpowers/plans/2026-10-21-parent-portal-branch-1-access.md`; definition of done = Q23 (a)–(d).

## Global Constraints

- Branch `test/parent-e2e` (created from bce1fe5); no commits by Claude; finish with a `git add` list and a commit message ending `Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>`.
- No application behaviour change. If a test exposes a real leak, fix it test-first and ledger it as a finding.
- Parent routes: group `parent`, filters `role:parent`, `readonly`, `permission:children.view`; GET only except `POST parent/profile/notifications` and `POST parent/profile/password-link`.
- A foreign or unlinked child/offering answers **404**, never 403.
- Settings service caches: tests that switch the portal off call `Services::resetSingle('settings')` in setUp/tearDown.
- Seeders are not idempotent; the e2e server reseeds on every run.
- Known local-only failures stay: six `AiWorkerEndpointsTest` tests.

## Review Focus

- A parent linked to child A requests child B's course/calendar/report by id swap → 404 (matrix).
- A child who is staff (instructor/chairperson) linked to a parent → parent pages skip staff data (canary).
- A dropped/past course shows no per-item score and no feedback (canary).
- Portal flag off mid-session → every child page shows the "off" state, no data (matrix).
- Demo seed run twice by mistake does not create duplicate links (seeder guards on existing links).

---

### Task 1: Demo seed for parents

**Files:**
- Create: `app/Database/Seeds/DemoParentLinksSeeder.php`
- Modify: `app/Database/Seeds/DatabaseSeeder.php` (call after `DemoActivitySeeder`), `app/Database/Seeds/DemoUsersSeeder.php` (second parent), `tests/e2e/support/sign-in.js` (accounts `parent`, `parentUnlinked`)
- Test: `tests/database/DemoParentLinksSeederTest.php`

**Interfaces:**
- Produces: `parent1@mentora.test` (Parent123!) linked to **student1** (enrolled in IT101-A and IT102-A) and **student5** (IT101-A and IT201-A); `parent2@mentora.test` (Parent123!, username `parent.demo2`, "Linda Reyes") linked to nobody. Accounts `accounts.parent`, `accounts.parentUnlinked` in `sign-in.js`.

- [ ] **Step 1: Failing test** `testTheDemoParentIsLinkedToTwoStudentsAndTheOtherToNone`: run `DemoUsersSeeder`, a minimal enrolment fixture is not needed — seed the real chain via `DatabaseSeeder` in a non-isolated class? Use the existing seeders' pattern in `tests/database` for demo seeders (check `DemoActivitySeederTest` if present); assert `ParentAccessService->children(parent1)` has 2 students, one with ≥2 enrolments, and parent2 has 0.
- [ ] **Step 2: Run, expect FAIL** (`vendor/bin/phpunit tests/database/DemoParentLinksSeederTest.php`).
- [ ] **Step 3: Implement** the seeder through `ParentLinkService::createLinks` (so rules hold); guard: skip if the parent already has links. Add parent2 to `DemoUsersSeeder`; register in `DatabaseSeeder` after `DemoActivity`.
- [ ] **Step 4: Run, expect PASS.**

### Task 2: Authorization matrix over the route table

**Files:** Test: `tests/feature/ParentAuthorizationMatrixTest.php`

**Interfaces:** Consumes `RouteTestCase`, `PeopleFactory`, `CourseFactory`, `Services::routes()`.

- [ ] **Step 1: Write tests** (all expected to pass against existing code; they are a regression net, so also mutate once to prove they bite — remove the `parentchild` filter from one route locally, see the test fail, restore):
  - `testEveryParentRouteIsCoveredByTheMatrix`: collect all routes starting `parent`, assert each is in the test's known list (a new route fails until listed).
  - `testEveryRoleButParentIsTurnedAwayFromEveryParentRoute` (student, instructor, chairperson, dean, institutional admin, admin; anonymous → login).
  - `testAnUnlinkedChildOrOfferingIs404OnEveryChildRoute` (swap child id, swap offering id, child exists but not enrolled).
  - `testTheOnlyPostsAParentCanMakeAreTheirOwnAccountForms`: every non-GET route in the group equals the two profile posts; POST/PUT/DELETE to every GET route → 404/405, nothing written (row counts unchanged).
  - `testSwitchingThePortalOffShowsNoChildDataOnAnyChildPage`.
- [ ] **Step 2: Run, expect PASS** (note any failure as a finding and fix test-first).

### Task 3: Privacy-canary sweep

**Files:** Test: `tests/feature/ParentPrivacyCanaryTest.php`

- [ ] **Step 1: Fixture** — one child enrolled in a course with: a quiz and an assignment with per-item scores, manual gradebook item, submission text, feedback text, a discussion thread with reply and attachment, a module with resource names, and a dropped second course. Every private string contains a unique `CANARY-` marker.
- [ ] **Step 2: Test** `testNoPrivateContentAppearsInAnyParentResponse`: as the linked parent, GET every parent URL (dashboard, child dashboard, calendar page + events API, four tab pages, four tab APIs, grade report PDF text, notifications page, profile); assert no `CANARY-` marker (per-item score values, submission, feedback, discussion, resource names), no `modules`/`discussions`/`resources` tab link, and response JSON never contains keys `submissions`, `feedback`, `items` under grades.
- [ ] **Step 3: Test** `testNotificationTextCarriesNoGrades`: trigger `ParentNotifier` for a finalized grade; assert body has no digit-percent pattern or score.
- [ ] **Step 4: Run, expect PASS**; mutate once (echo a canary into a tab) to prove RED.

### Task 4: Playwright journeys

**Files:** Create: `tests/e2e/parent.spec.js`; Modify: `tests/e2e/support/sign-in.js`

- [ ] **Step 1: Specs** (`signInAs(page, accounts.parent, "parent")`): dashboard lists both children and switcher changes child; child's course opens with exactly Overview/Announcements/Assessments/Grades tabs and no editing controls; calendar is read-only (no Add button); direct URL to a course of an unlinked student (student2's IT201-A) is 404; grade report download is final-only message or PDF; profile toggles a child's email switch and saves; unlinked parent (`parent2`) sees the explanation; student5 `student/profile` lists parent Roberto; institutional admin invites a parent with students (reuse people page flow) and the invite appears.
- [ ] **Step 2: Run** `npx playwright test tests/e2e/parent.spec.js`; expect all pass; run `chairperson.spec.js`/`gradebook.spec.js` once for regression.

### Task 5: Test-case catalog and docs

**Files:** Create: `docs/test-cases/p2-parent.md` (TC-PAR-01…, Draft, same fields as `p2-gradebook.md`); Modify: `docs/test-cases/README.md` (area `PAR`, file list), demo-login docs where parents are listed.

- [ ] One case per Playwright spec plus the manual-only ones (email delivery, cron `parents:notify`); each cites its PHPUnit tests.

### Task 6: Full verification

- [ ] Full PHPUnit in background (expect only the six AiWorker failures), PHPStan, ESLint, Vitest 371/371, Playwright full run. Self-review of the branch diff (no sub-agent; disclosed). Report: files, results, `git add` list, commit message.
