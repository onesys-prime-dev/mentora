# Audit Plan 5 — The Course Role Decides; View-As Writes Nothing; One Quiz Attempt Across Tabs Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:**
- **Course role (finding 9):** what someone may do in a course depends only on their role in that course, and nobody is ever both a student and staff of one course (9.1–9.7, D7, D10).
- **View-as (finding 3):** viewing as someone writes nothing on their behalf. Opening a quiz shows its landing information and never starts an attempt (3.1, 3.2).
- **Two tabs (finding 15):** a quiz opened in two tabs is one attempt, the last save of each answer wins, and nothing lands after submit (15.1, 15.2).

**Architecture:**
- **One answer to "what are you in this course":** `CourseMembership::roleIn(User, offeringId)` returns `'instructor' | 'chairperson' | 'student' | null`. The 26 `inGroup('instructor'|'student')` checks ask it when they are about one course, and ask the active role when they are not (lists, the dashboard).
- **One guard for every writer:** `CourseRoleConflicts` gives the reason an enrolment or a staff role would clash, or null. Every writer of enrolments and staff roles calls it: the enrolment page, the CSV upload and SIS import, instructor, chair and dean assignment, and granting the administrator role.
- **View-as:** `Impersonation::current()` is the one switch. The quiz start skips its insert and renders a view-as state, Shield's last-active stamp is off for the request, and a crawl test proves that no GET writes anything but the audit log.
- **Quiz concurrency:** the attempt insert treats a duplicate key as "the other tab started it". Answers get a unique key and an upsert, and save and submit lock the submission row.

**Tech Stack:** CodeIgniter 4.7 + Shield, MySQL, PHPUnit 10 (`DatabaseTestCase`, `RouteTestCase`, `CourseFactory`, `PeopleFactory`, `DeanFactory`, `GradeFactory`, `SisFixture`), Playwright.

**Spec:** `docs/superpowers/specs/2026-10-23-audit-fixes-design.md` § Findings 3, 9, 15 and § 3 (order: 9, then 3, then 15, because all three touch `QuizAttemptService`). Decisions: `docs/audit-2026-10-23-decisions.md` 3.1–3.2, 9.1–9.7, 15.1–15.2, D7, D10.

## Global Constraints

- **Branch and commits:** branch `fix/course-role`, created from `fix/approvals` at 48543c7. No commits by Claude: each commit step stages files and states the message. Messages end `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`.
- **Line endings:** CRLF files (`app/Config/Routes.php`, several services and tests) are edited with the Edit tool, or with a Python script written to a file with the Write tool (shell heredocs collapse backslashes). Never `sed -i` on them.
- **Role in a course (9.1):**
  - `instructor`: assigned to teach that offering.
  - `chairperson`: actively chairs the sub-department of the offering's program.
  - `student`: enrolled in that offering (`enrolled` or `completed`).
  - If more than one applies, the order is instructor, then chairperson, then student. Only chair-and-instructor can still overlap once the guard is in.
- **The conflicts (9.2–9.5, D7, D10).** "Course" means `courses.id`: all its sections in all terms. Enrolling someone is refused when they:
  - have an instructor assignment on any offering of the course, in any term ("ever taught");
  - have any chairperson assignment, active or ended, on the course's sub-department ("ever chaired");
  - have any dean assignment, active or ended, on the course's department ("ever led", D10);
  - hold the `institutional_admin` role in the course's institution.

  Granting a staff role is refused when the person:
  - **teaching an offering:** is enrolled (`enrolled` or `completed`) in an offering of the same course in the same term (D7);
  - **chairing a sub-department:** has an `enrolled` enrolment in a course of that sub-department;
  - **dean of a department:** has an `enrolled` enrolment in a course of that department;
  - **administrator of an institution:** has an `enrolled` enrolment in a course of that institution.

  A `completed`, `dropped` or `withdrawn` enrolment never blocks a staff role in a later term (D10). A former student may later teach or chair the course.
- **Assumptions to confirm when reviewing this plan:**
  - Removing an instructor by hand deletes the row (`OfferingService::unassignInstructor`), so "ever taught" counts only assignments that still exist. A removal is treated as a correction, as if they never taught it.
  - Roles keep no history, so a former institution administrator is not known.
  - Both cases are recorded as rulings. If the user wants stronger rules, instructor assignments get a soft end in a later plan.
- **Exact copy.** The guard returns a predicate. Lists put it after the person's name ("Ann Lee teaches or has taught this course"); a single refusal is "{name} {predicate}."
  - **enrol, taught:** "teaches or has taught this course"
  - **enrol, chaired:** "chairs or has chaired the sub-department of this course"
  - **enrol, dean:** "is or was the dean of this course's department"
  - **enrol, admin:** "is an administrator of this institution"
  - **teach:** "is enrolled in this course this term"
  - **chair:** "is enrolled in a course of this sub-department"
  - **dean:** "is enrolled in a course of this department"
  - **admin:** "is enrolled in a course of this institution"
- **Existing conflicts (9.6, 9.7):** a migration ends the enrolment (status `dropped`, the work kept, as `EnrolmentService` never deletes) and keeps the staff role. It writes an `enrolment.status_changed` audit entry with the reason "course role conflict".
- **View-as rule (3.2):** while `Impersonation::current() !== null`, nothing is saved on the viewed person's behalf, even on a GET.
  - **Still written:** audit entries, which carry the administrator as impersonator, and the view's own start and stop.
  - **Only one switch:** `Impersonation::current()`. No second flag.
- **Quiz view-as state (3.1):**
  - **What it shows:** availability, attempts used of the limit, and past results (attempt, date, score, status).
  - **In progress:** an attempt in progress is shown read-only, with its remaining time and saved answers.
  - **Never:** a started attempt or unseen questions.
  - **Exact copy of the notice:** "You are viewing as {name}. Opening the quiz here does not start an attempt."
- **Two tabs (15.1, 15.2):** a second start at the same moment resumes the same attempt. The last save of each question wins, and after submit, saves and a second submit are refused with today's messages ("This attempt is no longer in progress." and "This attempt has already been submitted.").
- **Known local failures:** the six `AiWorkerEndpointsTest` failures are known. Run the full suite as `vendor/bin/phpunit --no-coverage`, and never alongside Playwright.

## Review Focus

- **The two-role person:** an instructor of course A is enrolled as a student in course B. In B they meet every student rule: attempt limit, availability, the prerequisite lock, visibility. They never get the instructor's preview. In A they keep the instructor's view.
- **Every entry point refuses:** the enrolment page, the CSV upload, the SIS import, instructor assignment, chair assignment, dean assignment and the role grant each refuse a conflict with the exact predicate. A SIS row fails alone and the run continues.
- **D10 edges:**
  - a former student (a `completed` enrolment in a past term) may teach the course;
  - a former dean may never enrol in their old department's courses;
  - an enrolment ended in a past term never blocks a staff role.
- **View-as crawl:** every GET of the student, parent, instructor, chairperson and dean areas, while viewing as them, leaves every table unchanged except `audit_logs`. Opening a quiz creates no submission and shows no unseen question.
- **Two tabs:** two starts give one attempt, two saves give one row with the last value, and a save after submit is refused.

---

### Task 1: One answer to "what are you in this course" (9.1)

**Files:**
- Modify: `app/Services/Courses/CourseMembership.php`: add `roleIn(User $user, int $offeringId): ?string` per the Global Constraints. It uses the existing `InstructorAssignmentModel::retrieveInstructorCourseOffering`, `ChairpersonAccessService::canAccess` and `EnrollmentModel::retrieveStudentCourseOffering`, each only when the person holds that group. Results are memoized per request on `(userId, offeringId)`.
- Test: `tests/database/CourseRoleInTest.php`

- [ ] **Step 1: Write the failing tests**
  - `testAnInstructorOfTheOfferingIsItsInstructor`
  - `testAnInstructorOfAnotherCourseEnrolledHereIsAStudentHere`
  - `testTheChairOfTheProgramIsItsChairperson`
  - `testAChairWhoTeachesTheOfferingIsItsInstructor`
  - `testSomeoneWithNoTieIsNothing`

- [ ] **Step 2: Run them to verify they fail**

Run: `vendor/bin/phpunit --no-coverage tests/database/CourseRoleInTest.php`
Expected: FAIL, because `roleIn` is undefined.

- [ ] **Step 3: Implement as listed under Files.**

- [ ] **Step 4: Run them**

Expected: PASS.

- [ ] **Step 5: Stage**

Message: `feat: one answer to a person's role in a course`

---

### Task 2: The quiz obeys the course role (9.1, the finding itself)

**Files:**
- Modify: `app/Services/Courses/Assessments/QuizAttemptService.php`:
  - **Lines 45-51:** the enrolment check becomes `roleIn(...) === null → 404`.
  - **Line 70:** the student rules (visibility and availability) apply unless `roleIn` is `instructor` or `chairperson`. The instructor's preview attempt stays as it is today, but only for the course's own instructor or chairperson.
- Test: `tests/database/QuizCourseRoleTest.php`

- [ ] **Step 1: Write the failing tests.** Someone who holds the `instructor` group, teaches course A and is enrolled in course B:
  - `testInAnotherCourseAnInstructorMeetsTheAttemptLimit`: after `attempt_limit` attempts in B, the take page is blocked.
  - `testInAnotherCourseAnInstructorCannotOpenAHiddenOrClosedQuiz`: a draft quiz returns 404, and a closed one shows the availability reason.
  - `testAnInstructorWhoIsNeitherEnrolledNorTeachingGetsNotFound`.
  - `testTheCoursesOwnInstructorStillPreviews`.

- [ ] **Step 2: Run them to verify they fail.** The first three fail today, because `inGroup('instructor')` skips the rules.

- [ ] **Step 3: Implement.**

- [ ] **Step 4: Run them, and the quiz tests**

Run: `vendor/bin/phpunit --no-coverage tests/database/QuizCourseRoleTest.php` plus `grep -rl "QuizAttempt\|assessments/.*/take" tests/database tests/feature`
Expected: PASS.

- [ ] **Step 5: Stage**

Message: `fix: in a course, the quiz rules follow the person's role there`

---

### Task 3: The other course checks ask the course role (9.1)

**Files:** each `inGroup('instructor'|'student')` below is replaced as stated.
- **About one offering:** these use `roleIn($user, $offeringId)`, with the offering each already has in scope.
  - `AnnouncementService.php:69`
  - `AssessmentFeedbackService.php:40`: the submission's offering.
  - `AssignmentSubmissionService.php:116`
  - `CourseService.php:206, 216` (`getCourseOffering`)
  - `DiscussionService.php:74`
  - `LearningProgressService.php:792`
  - `ModuleService.php:78`
  - `ResourceService.php:73`
  - `FileAccessService.php:86`: the file's offering.
  - `CalendarEventService.php:77`: per offering in the loop.
- **`CourseViewer::seesInstructorView`:** it gains `?int $offeringId = null`. With an offering, it is `roleIn ∈ {instructor, chairperson}` or the read-only viewer; without one, it keeps today's group answer. Its course-page callers pass the offering (`grep -rn "seesInstructorView(" app`).
- **The three module views:**
  - `video.php:14`
  - `learning-item-panel.php:1`
  - `navigations.php:21`

  These read a `$courseRole` that their controller passes, computed once with `roleIn`. They do not call the service from the view.
- **Not about one course:** these use the active role (`ActiveRoleService::resolve`). They list or label across courses, and the person's active role is their answer there.
  - `CourseService.php:52, 61` (course cards)
  - `DashboardService.php:337, 707`
  - `CourseController.php:76` (URL prefix)
  - `CalendarEventService.php:147`
  - `ScheduleService.php:54`
  - `DepartmentAnnouncementService.php:398`
- **`CourseViewer.php:50` (`isInstitutionViewer`):** it stays a group check. It asks "is this the administrator", not a course role, and an administrator who teaches can't be enrolled anyway (Task 5).
- Test: `tests/database/CourseRoleChecksTest.php` and the existing course suites.

- [ ] **Step 1: Write the failing tests.** For the two-role person from Task 2, in course B:
  - `testTheyCannotPostAnAnnouncementInTheCourseTheyStudy`
  - `testTheirVideoProgressIsTrackedAsAStudents`
  - `testTheySeeTheStudentViewOfTheCourse` (`seesInstructorView($user, $b)` is false)
  - `testTheyCannotOpenAnotherStudentsFile`

- [ ] **Step 2: Run them to verify they fail.**

- [ ] **Step 3: Implement as listed.** Then run `grep -rn "inGroup('instructor'\|inGroup('student'" app`. What's left is the active-role list above, now written with `ActiveRoleService`, and `CourseViewer.php:50`.

- [ ] **Step 4: Run the new tests and the course suites**

Run: the test above, plus `vendor/bin/phpunit --no-coverage tests/database tests/feature --filter "Course|Module|Announcement|Discussion|Resource|Calendar|Schedule|Dashboard|FileAccess|LearningProgress|Assignment|Feedback"`
Expected: PASS.

- [ ] **Step 5: Stage**

Message: `fix: every course check asks the person's role in that course`

---

### Task 4: One guard for student-and-staff conflicts (9.2–9.5, D7, D10)

**Files:**
- Create: `app/Services/Courses/CourseRoleConflicts.php`, with:
  - `forEnrolment(int $userId, int $offeringId): ?string`
  - `forTeaching(int $userId, int $offeringId): ?string`
  - `forChairing(int $userId, int $subDepartmentId): ?string`
  - `forDean(int $userId, int $departmentId): ?string`
  - `forAdministrator(int $userId, int $institutionId): ?string`

  Each returns the predicate from the Global Constraints, or null. Queries only, with no writes.
- Test: `tests/database/CourseRoleConflictsTest.php`

- [ ] **Step 1: Write the failing tests**, one per rule and one per D10 edge:
  - `testSomeoneWhoTeachesASectionCannotEnrolInAnother`
  - `testSomeoneWhoTaughtTheCourseInAnEarlierTermCannotEnrol`
  - `testAChairOrFormerChairCannotEnrolInTheSubDepartmentsCourses`
  - `testADeanOrFormerDeanCannotEnrolInTheDepartmentsCourses`
  - `testAnAdministratorCannotEnrolInTheInstitutionsCourses`
  - `testAStudentCannotTeachTheCourseInTheSameTerm`
  - `testAFormerStudentMayTeachTheCourseInALaterTerm`
  - `testAnEnrolledStudentCannotBecomeChairDeanOrAdministratorThere`
  - `testAnEnrolmentEndedInAPastTermBlocksNoStaffRole`

- [ ] **Step 2: Run them to verify they fail**

Expected: FAIL, because the class doesn't exist.

- [ ] **Step 3: Implement.**

- [ ] **Step 4: Run them**

Expected: PASS.

- [ ] **Step 5: Stage**

Message: `feat: one guard for a person being both student and staff of a course`

---

### Task 5: Every writer calls the guard (9.2–9.5)

**Files:**
- `app/Services/Institutions/EnrolmentService.php`:
  - **`add`:** a conflict joins `$skipped` with the predicate, just as "is already enrolled" does today.
  - **`setStatus`:** to `enrolled`, a conflict is refused with a `StructureException`: "{name} {predicate}."
- `app/Services/Sis/SisImporter.php`:
  - **Enrolments (`:~480`):** a conflict fails the row with "{external id} {predicate}." The run continues. The CSV upload goes through the same importer.
  - **Staff (`applyStaff`):** the same, for instructor, chair and dean rows.
- `app/Services/Institutions/OfferingService.php::assignInstructor` uses `forTeaching`.
- `app/Services/Institutions/ChairAssignmentService.php::assign` uses `forChairing`.
- `app/Services/Institutions/DeanAssignmentService.php::assign` uses `forDean`.
- `app/Services/Users/UserAdminService.php` (`syncGroups`, `:~304`) uses `forAdministrator` when the roles gain `institutional_admin`.
- `app/Services/Institutions/InvitationService.php:402`: an accepted invitation into an existing account with enrolments uses the same check. A new account has none.
- **Not guarded:** `TermCloseService` reopening a term only puts back enrolments that existed and were checked when made.
- Test: `tests/database/CourseRoleWritersTest.php` and `tests/feature/EnrolmentConflictPageTest.php`

- [ ] **Step 1: Write the failing tests:** one per writer, each asserting the exact message and that nothing was written:
  - `testTheEnrolmentPageSkipsAConflictWithItsReason`
  - `testReinstatingAConflictingEnrolmentIsRefused`
  - `testTheSisEnrolmentRowFailsAloneAndTheRunContinues`
  - `testTheSisStaffRowFailsAlone`
  - `testAssigningAnEnrolledStudentToTeachIsRefused`
  - `testAssigningAnEnrolledStudentAsChairIsRefused`
  - `testAssigningAnEnrolledStudentAsDeanIsRefused`
  - `testGrantingTheAdministratorRoleToAnEnrolledStudentIsRefused`
  - the page test: the enrolment page shows the skipped reason.

- [ ] **Step 2: Run them to verify they fail.**

- [ ] **Step 3: Implement as listed.**

- [ ] **Step 4: Run them, and the enrolment, staff and SIS suites**

Run: the tests above, plus `grep -rlE "EnrolmentService|assignInstructor|ChairAssignment|DeanAssignment|UserAdminService|SisImporter|Invitation" tests/database tests/feature`
Expected: PASS. Existing tests that set up a student who also teaches the course are rewritten to use two people, and each rewrite is ledgered.

- [ ] **Step 5: Stage**

Message: `fix: nobody is enrolled in a course they teach, chair or lead, from any entry point`

---

### Task 6: Existing conflicts are fixed (9.6, 9.7)

**Files:**
- Create: `app/Database/Migrations/2026-11-16-000001_EndCourseRoleConflicts.php`.
  - **`up()`:** for each `enrolled` or `completed` enrolment, `CourseRoleConflicts::forEnrolment` decides. A conflicting one becomes `dropped`, with an `enrolment.status_changed` audit entry (actor "system", reason "course role conflict"). The staff role stays.
  - **`down()`:** a no-op. A dropped enrolment can be reinstated by hand, which the guard then checks.
  - **The pure part:** a static `toEnd(list<row>)` that tests can call.
- Test: `tests/database/EndCourseRoleConflictsTest.php`

- [ ] **Step 1: Write the failing tests**
  - `testAnEnrolmentInATaughtCourseIsDroppedAndTheTeachingStays`
  - `testAnUnrelatedEnrolmentIsUntouched`
  - `testTheWorkOfTheDroppedEnrolmentIsKept`

- [ ] **Step 2: Run them to verify they fail.**

- [ ] **Step 3: Implement, and run the migration on dev** (`php spark migrate`).

- [ ] **Step 4: Run them**

Expected: PASS.

- [ ] **Step 5: Stage**

Message: `fix: existing student-and-staff conflicts end the enrolment and keep the staff role`

---

### Task 7: Viewing as someone writes nothing; the quiz shows its landing (3.1, 3.2)

**Files:**
- Modify: `app/Services/Courses/Assessments/QuizAttemptService.php::startAttempt`:
  - **No insert:** with `Impersonation::current() !== null`, it never inserts.
  - **What it reads:** the in-progress attempt (if any), the attempts used, the attempt limit, the availability reason and the past attempts (`status != 'In Progress'`: attempt number, submitted at, score and percentage, status).
  - **Questions and answers:** with an attempt in progress, the questions and saved answers load as today but are marked read-only. Without one, no questions load.
  - **View flag:** it passes `viewAs => ['name' => …]` to the view.
- Modify: `app/Views/pages/courses/features/assessments/quiz-answer-form.php`: the view-as state, in the take page's own look.
  - **Without an attempt in progress:** the notice from the Global Constraints, the availability, "{n} of {limit} attempts used", and a past-results table (or "No attempts yet.").
  - **With one:** the questions with inputs `disabled`, no save or submit controls, and the remaining time shown but not counting toward an auto-submit.
- Modify: `app/Filters/ImpersonationFilter.php::before`: once a view is active and still valid, `config('Auth')->recordActiveDate = false;`. The filter runs in `globals` before Shield's `session` filter, which reads `setting('Auth.recordActiveDate')`.
- **Writes the crawl finds:** any other GET-time write the crawl test finds is skipped the same way, guarded by `Impersonation::current() !== null` where it happens. Each one found is ledgered.
- Test:
  - `tests/feature/ViewAsQuizTest.php`
  - `tests/feature/ViewAsWritesNothingTest.php`, the crawl. For each of student, parent, instructor, chairperson and dean:
    - start a view as that person;
    - request every GET route of their area from `service('routes')->getRoutes('get')`, with `(:num)` filled from that person's seeded offering, assessment and child;
    - snapshot `SELECT COUNT(*), MAX(updated_at)` of every table (`$db->listTables()`) before and after;
    - assert that nothing changed except `audit_logs`.

    Routes that answer 404 for the filled ids are fine. The test lists the routes it skipped and why (downloads that need a real file).

- [ ] **Step 1: Write the failing tests**
  - `testViewingAsAStudentTheQuizShowsItsLandingAndStartsNothing`: no `assessment_submissions` row, the notice is shown, and no question text appears.
  - `testAnAttemptInProgressIsShownReadOnly`: its saved answer is visible, inputs are disabled, and there is no submit button.
  - `testPastResultsAreListed`
  - `testViewingAsDoesNotStampTheirLastActive`
  - the crawl test.

- [ ] **Step 2: Run them to verify they fail.** The first one fails because a submission is created. The crawl lists today's writes.

- [ ] **Step 3: Implement**, and add a guard for each write the crawl reports, until it passes.

- [ ] **Step 4: Run them, and the view-as and quiz suites**

Run: the tests above, plus `grep -rlE "Impersonation|ViewAs|QuizAttempt" tests/database tests/feature`
Expected: PASS.

- [ ] **Step 5: Stage**

Message: `fix: viewing as someone saves nothing for them, and a quiz shows its landing`

---

### Task 8: One attempt across two tabs; the last save wins; nothing after submit (15.1, 15.2)

**Files:**
- Create: `app/Database/Migrations/2026-11-16-000002_OneResponsePerQuestion.php`. It deletes duplicate `(submission_id, question_id)` rows, keeping the newest (`updated_at`, then `id`), then adds the unique `uq_response_question (submission_id, question_id)`.
- Modify: `app/Services/Courses/Assessments/QuizAttemptService.php`:
  - **`startAttempt` (the insert at `:100-110`):** a `DatabaseException` from `uq_submission_attempt` re-reads the in-progress attempt and carries on with it. The read moves into a protected `inProgressAttempt(int $assessmentId, int $userId): ?array`, which gives tests a seam.
  - **`saveAnswer`:**
    - in one transaction, `SELECT id, status FROM assessment_submissions WHERE id = ? FOR UPDATE`;
    - then the status and time checks;
    - then one upsert (`$db->table('assessment_question_responses')->upsert($responseData)`, keyed by the new unique key);
    - the check-then-insert at `:210-238` goes.
  - **`submitAttempt`:**
    - the same lock first;
    - the final `update` is conditional on `status = 'In Progress'`;
    - a second submit gets "This attempt has already been submitted." and syncs nothing.
- Test: `tests/database/QuizTwoTabsTest.php`

- [ ] **Step 1: Write the failing tests**
  - `testASecondStartAtTheSameMomentResumesTheSameAttempt`: a subclass's `inProgressAttempt` returns null once, while the other tab's row exists. The result is one submission row, and the same submission id is returned.
  - `testTwoSavesOfOneQuestionLeaveOneRowWithTheLast`
  - `testASaveAfterSubmitIsRefused`
  - `testASecondSubmitIsRefusedAndSyncsNothing`
  - `testTheMigrationKeepsTheNewestDuplicate`

- [ ] **Step 2: Run them to verify they fail.** The first fails with a duplicate-key error. `testTheMigrationKeepsTheNewestDuplicate` fails because the class doesn't exist.

- [ ] **Step 3: Implement, and migrate dev.**

- [ ] **Step 4: Run them, and the quiz suites**

Run: the tests above, plus the Task 2 command
Expected: PASS.

- [ ] **Step 5: Stage**

Message: `fix: a quiz open in two tabs is one attempt, and nothing lands after submit`

---

### Task 9: Full check and docs

- [ ] **Step 1: Run every suite, one at a time**

Run: `vendor/bin/phpunit --no-coverage`, then `npm test`, then `npm run build && npm run e2e`
Expected: PASS, apart from the six known `AiWorkerEndpointsTest` failures and the known calendar flake. TC-QUIZ-01 is re-run alone if it fails, since it flaked once in plan 4.

- [ ] **Step 2: Docs.**
  - **`docs/course-access.md`:** the course role (`roleIn`) and the conflict rules with their exact predicates.
  - **`docs/roles-and-permissions.md`:** a role in a course outranks the group.
  - **`docs/institutional-admin-area.md`:** in "Viewing as one of the institution's people", nothing is saved for the person, and the quiz landing state; in Enrolment, the skipped reasons.
  - **`docs/sis-integration.md`:** the conflict failures for enrolment and staff rows.

- [ ] **Step 3: Stage the plan file and the docs**

Message: `docs: the course role, view-as writing nothing, and quiz attempts across tabs`
