# Assessment settings: randomize, specific students, password and additional security

Where: the settings panel of the assessment editor (`partials/editor-inspector-panel.php`). Items marked
**quiz only** do not appear on an assignment, and the server refuses to store them for one.

Everything here is **enforced by the server**. A setting that nothing enforces is not stored (see
`testSettingsThatNothingEnforcesAreNotStored`). Run `php spark migrate` for the two migrations
`2026-11-24-000001` and `2026-11-24-000002`.

## What each setting does

| Setting | For | What happens |
|---|---|---|
| Randomize questions | quiz | Each attempt gets its own order. It is shuffled once when the attempt starts, so resuming keeps the order. |
| Specific students | every assessment | Only the chosen students see it, take it or hand it in. A toggle, a count ("3 students selected") and a list with Select all. |
| Require a password | quiz | Students type it before the attempt starts. The password is stored as a **hash** and is never sent back to a page. |
| Additional security: record leaving the page | quiz | Records that the student left the page, when, and for how long. |
| Additional security: lock the browser | quiz | Asks for fullscreen and turns off copy, cut, paste, right-click and Ctrl/Cmd+P, recording each try and leaving fullscreen. |

### What a web page cannot do (said to the teacher and the student)

A page **cannot see which tab or site** a student went to, and it **cannot stop** them switching tabs or
windows. Only a locked-down exam browser can. So "record leaving the page" records only that they left, and
"lock the browser" discourages and records; it does not prevent. The settings text and the notice a student
sees say so. The report is a prompt to look, not proof.

## Password

- Created in a modal: a plain text field pre-filled with a generated password (10 characters, no look-alike
  characters). The teacher can keep it, ask for another, or erase it and type their own (4 to 64 characters).
- Saved by its own request (`PATCH .../assessments/{id}/password`), so a password is only ever sent when set.
  Afterwards the panel shows `******` and an edit icon that opens the same modal.
- Switching "Require a password" on with no password created is refused (page and server).
- A student gets the same page, which only asks. The password is posted to `.../assessments/{id}/take`; the
  attempt starts only when it is right. Five wrong tries in ten minutes lock that student out for the rest of
  the window (`quiz_password_failures`). Resuming an attempt already started needs no password. Teachers are
  never asked.

## Specific students

- `assessment_settings.assign_to_specific_students` is the toggle; `assessment_assignees` is who.
  The list is only used while the toggle is on, so switching it off and on again remembers the choice.
- The picker lists the **students enrolled in the assessment's section**. The server refuses any other id.
- Saving the settings with the toggle on and nobody chosen is refused: nobody would see it.
- Not chosen means: not in the student's Assessments list, their calendar or the activity feed, a 404 by link,
  no attempt, no submission, not in the grading list, no "missing work" alert to a parent, and not a zero in a
  final grade (the grading engine leaves the item out for that student).
- Routes: `GET` and `PUT` `.../assessments/{id}/assignees` (teaching groups only).

## What the teacher sees of an attempt

On the grading page, an attempt that recorded anything shows a collapsible "Quiz security" line (times left the
page, time away, fullscreen exits, blocked actions) and the events in order (`partials/security-report.php`,
data from `QuizAttemptService::securityReport`). Events are limited to 100 per attempt and a student can only
report on their own attempt in progress.

## Where the code is

- Service: `AssessmentService` (settings, password, assignees, `studentVisibleIds`), `QuizAttemptService`
  (password gate, shuffle, security events), `GradingEngineService` (restricted items).
- Page scripts: `inspector/quiz-security.js`, `inspector/specific-students.js`, `quiz-taking/password-gate.js`,
  `quiz-taking/attempt-guard.js`.
- Tests: `tests/database/AssessmentSecuritySettingsTest.php`, `QuizSecurityAttemptTest.php`,
  `QuizSecurityEventsTest.php`, `QuizGuardPageTest.php`, `AssessmentAssigneesTest.php`;
  `tests/feature/Quiz*RouteTest.php`, `AssessmentAssigneesRouteTest.php`; `tests/unit/*SecurityPanelViewTest.php`,
  `QuizSecurityReportViewTest.php`; the `tests/js/pages/*quiz*` and `specific-students` tests; and
  `tests/e2e/quiz-security.spec.js` (TC-QSEC-01 to 04), `specific-students.spec.js` (TC-QSEC-05 to 07).

## Known gap

The gradebook screens that count missing work read stored scores, and a student not chosen has none, so they
are left out by the grading engine only. If another report counts "students without a score", it should also
skip students the assessment was not given to (use `AssessmentService::isAssignedTo`).
