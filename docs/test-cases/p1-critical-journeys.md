# P1 test cases: the critical journeys

17 P1 journey cases, 11 quiz cases added in Wave 1 of the QA plan, 25 in Wave 2, 28 in Wave 3 and 15 in Wave 4 (2026-10-28, at the end of this file, each with its own priority). Read [README.md](README.md) first for the format, the demo accounts and
how to reseed. **All are `Draft`**: derived from the code, the seed data and the
existing automated tests, awaiting a person to confirm each expected result.
"(wording to confirm)" marks on-screen text read from code but not yet seen in
a browser.

Run order matters where a case says "uses the result of"; otherwise any order
works on a fresh seed. A full run needs a fresh seed.

| ID | Case | Role |
|---|---|---|
| [TC-AUTH-01](#tc-auth-01-student-signs-in) | Student signs in | Student |
| [TC-AUTH-02](#tc-auth-02-instructor-signs-in) | Instructor signs in | Instructor |
| [TC-AUTH-03](#tc-auth-03-wrong-password-is-refused) | Wrong password is refused | Anonymous |
| [TC-AUTH-04](#tc-auth-04-signing-out-ends-the-session) | Signing out ends the session | Student |
| [TC-AUTH-05](#tc-auth-05-protected-pages-need-the-right-role) | Protected pages need the right role | Anonymous, Student |
| [TC-ANN-01](#tc-ann-01-instructor-posts-an-announcement-and-the-section-sees-it) | Instructor posts an announcement; the section sees it | Instructor, Student |
| [TC-ANN-02](#tc-ann-02-people-outside-the-section-cannot-post-or-read) | People outside the section cannot post or read | Student, Instructor |
| [TC-MOD-01](#tc-mod-01-items-unlock-in-order) | Items unlock in order | Student |
| [TC-MOD-02](#tc-mod-02-draft-work-is-hidden-from-students) | Draft work is hidden from students | Instructor, Student |
| [TC-QUIZ-01](#tc-quiz-01-student-takes-and-submits-a-quiz) | Student takes and submits a quiz | Student |
| [TC-QUIZ-02](#tc-quiz-02-the-attempt-limit-stops-another-attempt) | The attempt limit stops another attempt | Student |
| [TC-QUIZ-03](#tc-quiz-03-a-past-due-quiz-is-closed) | A past-due quiz is closed | Student |
| [TC-ASG-01](#tc-asg-01-student-submits-an-assignment) | Student submits an assignment | Student |
| [TC-ASG-02](#tc-asg-02-a-late-submission-is-accepted-and-marked-late) | A late submission is accepted and marked late | Student |
| [TC-GRD-01](#tc-grd-01-instructor-grades-an-assignment-with-a-rubric) | Instructor grades an assignment with a rubric | Instructor, Student |
| [TC-GRD-02](#tc-grd-02-instructor-grades-a-quizs-essay) | Instructor grades a quiz's essay | Instructor, Student |
| [TC-GRD-03](#tc-grd-03-student-sees-their-grades) | Student sees their grades | Student |

---

## Sign-in and roles

### TC-AUTH-01 Student signs in

- **Role:** Student. **Priority:** P1
- **Data:** `student5@mentora.test` / `Student123!`
- **Steps:**
  1. Open the site while signed out. You are sent to the login page.
  2. Enter the email and password, and press Login.
- **Expected:**
  - You land on the student dashboard (`/student/dashboard`).
  - The sidebar offers Courses, Calendar and Schedule (wording to confirm).
- **Already automated:** `tests/feature/AuthFlowTest.php`:
  `testALoggedOutVisitorOnTheHomePageGoesToLogin`, `testStudentsLandOnTheStudentDashboard`
- **Playwright:** `tests/e2e/auth-login.spec.js`
- **Basis:** `app/Controllers/Auth/RedirectController.php`, `app/Views/pages/auth/login.php`
- **Review:** Confirmed by Shuay, October 6, 2026

### TC-AUTH-02 Instructor signs in

- **Role:** Instructor. **Priority:** P1
- **Data:** `instructor1@mentora.test` / `Instructor123!`
- **Steps:** as TC-AUTH-01.
- **Expected:** you land on the instructor dashboard (`/instructor/dashboard`), not the student one.
- **Already automated:** `AuthFlowTest::testInstructorsLandOnTheInstructorDashboard`
- **Playwright:** `tests/e2e/auth-login.spec.js`
- **Basis:** `RedirectController.php`
- **Review:** Confirmed by Shuay, October 6, 2026

### TC-AUTH-03 Wrong password is refused

- **Role:** Anonymous. **Priority:** P1
- **Data:** `student5@mentora.test` with the password `wrong-password`
- **Steps:** open the login page, enter the email and the wrong password, press Login. Then repeat with an
  email that has no account (for example `nobody@mentora.test`).
- **Expected:**
  - You stay on the login page and are not signed in.
  - A banner above the form says: "Unable to log you in. Please check your credentials." (confirmed by
    calling the real login route).
  - **The banner is identical for an email with no account and for a real email with a wrong password**, so
    the page never reveals which emails are registered.
  - Opening `/student/dashboard` still sends you to login.
  - Also refused, none signs you in: a password that differs only in letter case, has a trailing space or is
    one character short, and an empty password (the form asks for it).
  - The right password still works straight after a failed attempt.
- **Already automated:** `tests/feature/AuthFlowTest.php`: `testAWrongPasswordIsRefusedAndNobodyIsSignedIn`,
  `testTheLoginPageDoesNotRevealWhichEmailsHaveAnAccount`, `testAnEmailWithNoAccountIsRefusedToo`,
  `testAnEmptyPasswordIsRefusedByTheForm`, `testAPasswordThatIsAlmostRightIsRefused`,
  `testTheRightPasswordSignsInEvenAfterAFailedAttempt`
- **Playwright:** `tests/e2e/auth-login.spec.js`
- **Basis:** `app/Language/en/Auth.php` (the override that makes both messages the same),
  `vendor/codeigniter4/shield/src/Language/en/Auth.php`, `login.php` (error banner)
- **Review:** Confirmed by Shuay, October 6, 2026
- **Note:** the two messages used to differ ("check your password" for a real email, "check your credentials"
  for an unknown one), which revealed registered emails. Fixed by the language override above. Still open: how
  long the refusal takes may differ between a real and an unknown email (a real email has a password hash to
  check), which a determined person could measure. Not covered by any test.

### TC-AUTH-04 Signing out ends the session

- **Role:** Student. **Priority:** P1
- **Data:** `student5@mentora.test`, signed in
- **Steps:** sign out from the account menu; then open `/student/courses` directly.
- **Expected:** you are on the login page after signing out, and `/student/courses` sends you back to login.
- **Already automated:** `AuthFlowTest::testLoggingOutEndsTheSession`
- **Playwright:** `tests/e2e/auth-login.spec.js`
- **Basis:** `app/Config/Auth.php` (`logout` redirect to `login`)
- **Review:** Confirmed by Shuay, October 6, 2026

### TC-AUTH-05 Protected pages need the right role

- **Role:** Anonymous, then Student. **Priority:** P1
- **Steps:**
  1. Signed out, open `/student/dashboard`, `/instructor/dashboard` and `/files/1`.
  2. Sign in as `student5@mentora.test` and open `/instructor/dashboard`.
- **Expected:**
  - Step 1: each address sends you to the login page.
  - Step 2: you are turned away (redirected), not shown the instructor dashboard.
- **Already automated:** `tests/feature/RouteAccessTest.php` checks every `/student` and
  `/instructor` route both ways (336 checks); the post-deploy smoke test checks four of them on staging.
- **Playwright:** no (fully covered by the PHP tests)
- **Basis:** `app/Config/Routes.php` (`group:student`, `group:instructor` filters)
- **Review:** Confirmed by Shuay, October 6, 2026

---

## Announcements

### TC-ANN-01 Instructor posts an announcement and the section sees it

- **Role:** Instructor, then Student. **Priority:** P1
- **Data:** instructor `instructor2@mentora.test`, course IT102-A; student `student3@mentora.test` (enrolled in IT102-A)
- **Steps:**
  1. As the instructor, open IT102-A and its Announcements.
  2. Try to post with an empty subject.
  3. Post one with the subject "Quiz reminder" and a message.
  4. Sign out. Sign in as student3, open IT102-A, Announcements.
- **Expected:**
  - Step 2 is refused: "Add a subject before posting."
  - Step 3 succeeds: "Announcement posted." and it appears in the instructor's announcement list.
  - Step 4: student3 sees "Quiz reminder" with the message.
- **Already automated:** `tests/database/AnnouncementServiceTest.php`: `testTheInstructorPostsAnAnnouncement`,
  `testASubjectIsRequired`; `tests/feature/AnnouncementRoutesTest.php`: `testTheInstructorPostsEditsPinsAndDeletes`,
  `testStudentsReadReplyAndReactThroughTheirOwnRoutes`
- **Playwright:** `tests/e2e/announcements.spec.js`
- **Basis:** `app/Services/Courses/AnnouncementService.php`, `app/Config/Routes.php`
- **Review:** Confirmed by Shuay, October 7, 2026. Screen wording clarified afterwards (see Clarification); the behavior is unchanged.
- **Clarification (October 7, found while automating it in a browser):** on the page the Post button stays off until there is a subject, so step 2 cannot be sent at all: the refusal is a disabled button, not the message "Add a subject before posting." (that message is what the server answers if a post is sent some other way, and the PHP tests cover it). Step 3 shows no "Announcement posted." text on the page: the new announcement simply appears at the top of the list.

### TC-ANN-02 People outside the section cannot post or read

- **Role:** Student, Instructor. **Priority:** P1
- **Data:** the announcement from TC-ANN-01 (uses the result of it); `student5@mentora.test` (not in IT102-A); `instructor1@mentora.test` (does not teach IT102-A); `student3@mentora.test`
- **Steps:**
  1. As student5, look for IT102-A in Courses, then open its address directly.
  2. As student3 (enrolled), look for a way to post an announcement.
  3. As instructor1, try to open IT102-A or post to it.
- **Expected:**
  - Step 1: IT102-A is not listed and the direct address is refused as not found.
  - Step 2: a student should have no way to post (UI to confirm); posting by other means is refused ("Only the instructor can post announcements.").
  - Step 3: instructor1 cannot open or post to a section they are not assigned to.
- **Already automated:** `AuthFlowTest::testAStudentCannotReadACourseTheyAreNotEnrolledIn`,
  `testAnInstructorCanOnlyOpenTheirOwnSection`; `AnnouncementRoutesTest::testAStudentCannotUseTheInstructorPostRoute`,
  `testAnotherSectionIsNotFoundForStudentsAndInstructors`; `AnnouncementServiceTest::testStudentsAndOtherCoursesInstructorsCannotPost`
- **Playwright:** `tests/e2e/announcements.spec.js`
- **Basis:** `AnnouncementService.php`, the `assessmentscope` route filter
- **Review:** Confirmed by Shuay, October 7, 2026

---

## Modules and progress

### TC-MOD-01 Items unlock in order

- **Role:** Student. **Priority:** P1
- **Data:** `student3@mentora.test`, course IT102-A, module "Setting Up PHP", unit "Your Development Environment" (items in order: PDF "XAMPP Installation Guide", reading "Your First PHP Script", quiz "Quiz: PHP Setup"). In the seed student3 has already completed the PDF and nothing else there.
- **Steps:**
  1. Open the module. The PDF is done, the reading is open, and the quiz is locked.
  2. Try to open the quiz: click its row, then open it by its address (the course page with `?item=` and the quiz's id, as the Overview links do).
  3. Open "Your First PHP Script" and press Mark as Completed.
  4. Press Continue.
- **Expected:**
  - Step 1: the quiz row shows it is locked and says why: Complete "Your First PHP Script" first.
  - Step 2: clicking a locked row does nothing. Opened by its address, the Modules tab shows a notice, "Complete “Your First PHP Script” to unlock “Quiz: PHP Setup”.", with a button to open "Your First PHP Script", and does not show the quiz or Mark as Completed. The server refuses the item itself (HTTP 403) with the same message.
  - Step 3: the reading is completed right away (PDFs, readings and links complete immediately).
  - Step 4: Continue opens the quiz; it is no longer locked.
  - The lock is enforced by the server, not just the page.
- **Already automated:** `tests/database/LearningProgressServiceTest.php`:
  `testPreviousModeLocksUntilThePreviousItemIsCompleted`, `testTheFirstItemIsNotLockedEvenInPreviousMode`,
  `testStartedButUnfinishedDoesNotUnlock`, `testOneStudentsProgressDoesNotUnlockForAnother`
- **Playwright:** `tests/e2e/modules.spec.js`
- **Basis:** `app/Services/Courses/LearningProgressService.php` (`lockMessage`, header comment), `ModuleService::lockedItemRefusal`
- **Review:** Confirmed by Shuay, October 7, 2026. Data and screen wording were corrected afterwards (see Correction); the behavior is unchanged. Please re-check.
- **Note:** this case completes the reading, which unlocks "Quiz: PHP Setup" for TC-QUIZ-03. Run it first.
- **Correction (October 7, found while automating it in a browser):** the first version said student3 has no activity in IT102-A and used the PDF as the locked item. In the seed student3 has already completed the PDF, so the locked item is the quiz. It also described a locked item's page as "Mark as Completed disabled, hint 'Complete the required items first.'"; a locked item never shows that button at all, and the notice above is what a student sees.

### TC-MOD-02 Draft work is hidden from students

- **Role:** Instructor, then Student. **Priority:** P1
- **Data:** course IT101-A, unit "Introduction to Databases", draft quiz "Quiz 2: Database Basics"; `instructor1@mentora.test`; `student5@mentora.test`
- **Steps:**
  1. As the instructor, open the unit and Assessments. Note the draft quiz and its Draft status.
  2. As student5, open the same unit and the Assessments tab.
  3. As student5, try to open the quiz by its address.
- **Expected:**
  - Step 1: the instructor sees "Quiz 2: Database Basics" marked as a draft.
  - Step 2: student5 does not see it in the module or in the assessment list.
  - Step 3: not found, even through a direct link.
- **Already automated:** `tests/database/AssessmentServiceTest.php`: `testStudentsSeePublishedAndClosedAssessmentsOnly`,
  `testStudentsGetANotFoundForDraftsEvenThroughADirectLink`; `GradeServiceTest::testDraftAssessmentsAreHiddenFromStudents`
- **Playwright:** `tests/e2e/modules.spec.js`
- **Basis:** `DemoContentSeeder.php` (the draft item), `AssessmentService.php`
- **Review:** Confirmed by Shuay, October 7, 2026

---

## Quizzes

### TC-QUIZ-01 Student takes and submits a quiz

- **Role:** Student. **Priority:** P1
- **Data:** `student5@mentora.test`, course IT101-A, unit "Variables and Data Types". Quiz 1: Variables and Data Types: 20 points, 30 minutes, 2 attempts, open (due 3 days after seeding). Questions: (1) Which of these values is a string? [3 pts, correct answer `"42"`]; (2) Which two are valid variable names? [4 pts, correct: `total_score` and `firstName`]; (3) What data type holds only true or false? [3 pts, `boolean` or `bool`]; (4) A named value that cannot change once it is set is called a … [3 pts, `constant`]; (5) essay [7 pts, graded by the instructor].
- **Steps:**
  1. Complete the two items before the quiz: reading "What Is a Variable?" and PDF "Data Types Cheat Sheet".
  2. Open Quiz 1. It shows "0 of 2 attempts used", and Mark as Completed is off. Press Take Quiz.
  3. Answer questions 1 to 4 correctly and write a short essay. Each answer saves as you go.
  4. Press Submit Quiz and accept the confirmation ("Submit your answers? You won't be able to change them afterward.").
  5. Return to the quiz item in Modules.
- **Expected:**
  - Step 1: the quiz is locked until both are completed.
  - Step 2: "0 of 2 attempts used"; Mark as Completed is unavailable, with the hint "Submit the quiz to complete it."
  - Step 3: each answer is saved (the page shows "All changes saved").
  - Step 4: the four auto-graded answers score 13 of 20 points and the essay is not scored, so you land on the quiz with a score panel reading **13/20, "So far", "The rest is still being graded."**, the attempt is **Partially Graded**, and a Retake Quiz button is offered (a second attempt can be started). The full score appears only once the instructor has graded the essay.
  - Step 5: the item shows "1 of 2 attempts used" and "13/20 so far — the rest is being graded", and it counts as **completed**: handing the quiz in completes the item, with nothing to press (even while the essay is still being graded). The next item in the unit unlocks.
- **Already automated:** `tests/database/QuizAttemptServiceTest.php`: `testOnlyChoiceQuestionsAndKeyedShortAnswersGradeThemselves`,
  `testEachAutoGradedAnswerIsScoredAndMarked`, `testTheAttemptLimitStopsANewAttempt`, `testAnswersAreAcceptedWhileTimeRemains`;
  `tests/unit/ShortAnswerGraderTest.php` (case and spacing); `tests/feature/AssessmentSectionAccessTest.php::testAStudentCanTakeTheirOwnSectionsQuiz`;
  completing on submit: `QuizAttemptServiceTest::testHandingInAQuizCompletesItsItem`, `testAQuizStillWaitingForItsEssayIsCompletedToo`, `testCompletingTheQuizUnlocksTheItemAfterIt`
- **Playwright:** `tests/e2e/quizzes.spec.js`
- **Basis:** `DemoContentSeeder.php` (Quiz 1), `QuizAttemptService.php`
- **Review:** Confirmed by Shuay, October 7, 2026. Page wording was clarified afterwards (see Note); the expected behavior is unchanged.
- **Note (October 7, found while automating it in a browser):** the expected behavior above is the intended one, but the app did not do it: after submitting, the quiz item stayed "Not Yet Completed" until the student pressed Mark as Completed. That was a bug; it is fixed (handing in a quiz or an assignment now completes its item, and a migration completed the items of work handed in earlier; Mark as Completed still works). Two wording details: "Quiz submitted successfully." is the server's reply, not text on the page; and the page asks for a confirmation first and then shows the score panel described above.

### TC-QUIZ-02 The attempt limit stops another attempt

- **Role:** Student. **Priority:** P1
- **Data:** `student5@mentora.test`, course IT201-A, "Quiz: Keys and Relationships" (one attempt allowed, already taken and graded in the seed, still open)
- **Steps:** open the quiz and try to start it again.
- **Expected:** no new attempt starts. The quiz item shows "1 of 1 attempt used" and, in place of a Take Quiz button, the page says "You've already taken this quiz." (for a quiz with more attempts the text is "You've used all N attempts."), and the earlier result stays viewable (wording to confirm). The server refuses too: asked to start the quiz it answers "No attempts remaining. You've used all 1 allowed attempt for this quiz." with no answer form, and no second attempt is recorded.
- **Already automated:** `QuizAttemptServiceTest::testTheAttemptLimitStopsANewAttempt`, `testTheDefaultLimitIsOneAttempt`;
  `tests/database/AssessmentServiceTest.php::testAStudentWhoUsedAllAttemptsIsToldSo`
- **Playwright:** `tests/e2e/quizzes.spec.js`
- **Basis:** `AssessmentService.php` (the `$reason` match around line 610), `DemoActivitySeeder.php`
- **Review:** Confirmed by Shuay, October 7, 2026

### TC-QUIZ-03 A past-due quiz is closed

- **Role:** Student. **Priority:** P1
- **Data:** `student3@mentora.test`, course IT102-A, "Quiz: PHP Setup" (due 1 day before seeding; late attempts not allowed; unlocked by TC-MOD-01, which you run first)
- **Steps:** open the quiz and try to start it.
- **Expected:** it does not start. The quiz item shows "0 of 1 attempt used", no Take Quiz button, and the page says "The due date has passed and late attempts aren't allowed." (wording to confirm).
- **Already automated:** `QuizAttemptServiceTest::testAfterTheDueDateOnlyAQuizAllowingLateAttemptsStarts`,
  `testAQuizPastItsAvailabilityWindowDoesNotStart`, `testAClosedQuizDoesNotStartANewAttempt`
- **Playwright:** `tests/e2e/quizzes.spec.js`
- **Basis:** `AssessmentService.php::quizAvailabilityReason`
- **Review:** Confirmed by Shuay, October 7, 2026
- **Note:** other states of the same rule have their own wording: "This quiz opens <date>." before the window, "This quiz closed on <date>." after it, "This quiz is closed." when an instructor closes it.

---

## Assignments

### TC-ASG-01 Student submits an assignment

- **Role:** Student. **Priority:** P1
- **Data:** `student5@mentora.test`, course IT101-A, unit "Introduction to Databases": reading "What Is a Database?", then assignment "Lab 1: Designing a Table" (20 points, due 1 day after seeding; text, link and file all allowed; one submission)
- **Steps:**
  1. Complete the reading "What Is a Database?" so the lab unlocks.
  2. Open the lab. It says "Submit your work to complete it." and Mark as Completed is off. Press Submit with nothing entered.
  3. Open the Link tab, enter `ftp://example.com/table` and press Submit.
  4. Open the Text Entry tab (the form offers File Upload, Text Entry and Link, one at a time), clear the link, type the column list for a Students table and press Submit.
  5. Look at the page again.
- **Expected:**
  - Step 2: "Add your work before submitting."
  - Step 3: "Enter a full link starting with http:// or https://."
  - Step 4: the page shows the status **Submitted** and "Attempt 1 of 1". ("Your work was submitted." is the server's reply, not text on the page.)
  - Step 5: the form is gone and the page says "You've already submitted this assignment."
  - Handing the work in completed the item: back in Modules it shows as completed, with nothing to press.
- **Already automated:** `tests/database/AssignmentSubmissionServiceTest.php`: `testATextSubmissionIsRecordedAsSubmitted`,
  `testOnlyWebLinksAreAccepted`, `testTheSubmissionLimitIsEnforcedAndNumbered`, `testOnlyEnrolledStudentsCanSubmit`;
  completing on hand-in: `testHandingInAnAssignmentCompletesItsItem`, `testCompletingTheAssignmentUnlocksTheItemAfterIt`
- **Playwright:** `tests/e2e/assignments.spec.js` (the file upload variant: later)
- **Basis:** `app/Services/Courses/Assessments/AssignmentSubmissionService.php` (messages, `blockingReason`)
- **Review:** Confirmed by Shuay, October 7, 2026. Page wording clarified afterwards (see Note); the expected behavior is unchanged.
- **Note (October 7, found while automating it in a browser):** the page checks an empty submission and a link that is not a web address itself, with the messages above, and the server checks both again (the PHP tests cover that, which a browser test cannot tell apart). The item counting as completed was not true of the app until the fix that completes it on hand-in.

### TC-ASG-02 A late submission is accepted and marked late

- **Role:** Student. **Priority:** P1
- **Data:** `student4@mentora.test`, course IT201-A, assignment "ER Diagram Draft" (25 points, due 1 day before seeding, late submissions allowed, nothing submitted by student4)
- **Steps:** open the assignment, open the Text Entry tab, type an answer and press Submit.
- **Expected:** the page shows the status **Submitted** with a **Late** marker, and the submission is recorded as late. ("Your work was submitted." is the server's reply, not text on the page.) If the assignment did not allow late work, the page would instead say "The due date has passed and late submissions aren't allowed." (not seeded; the PHP tests cover it.)
- **Already automated:** `AssignmentSubmissionServiceTest::testASubmissionAfterTheDueDateWhereLateIsAllowedIsMarkedLate`
- **Playwright:** `tests/e2e/assignments.spec.js`
- **Basis:** `AssignmentSubmissionService.php`, `DemoContentSeeder.php` (`allow_late_submission`)
- **Review:** Confirmed by Shuay, October 7, 2026. Page wording clarified afterwards; the expected behavior is unchanged.

---

## Grading and grades

### TC-GRD-01 Instructor grades an assignment with a rubric

- **Role:** Instructor, then Student. **Priority:** P1
- **Data:** `instructor1@mentora.test`, course IT101-A, "Assignment 1: Grade Calculator" (30 points; rubric: Correctness 12, Code Readability 10, Testing and Output 8). student3's submission is in, late and ungraded. Afterwards `student3@mentora.test`.
- **Steps:**
  1. As the instructor open the assignment in Modules, press View Submissions and choose student3 (Maria Santos).
  2. Press Grade Work, open the Rubric tab, and try 13 for Correctness first.
  3. Enter 10 for Correctness, 8 for Code Readability and 6 for Testing and Output, and write a comment.
  4. Press Submit Grade.
  5. Sign in as student3 and open the assignment in Modules.
- **Expected:**
  - Step 2: 13 is held at the criterion's maximum, 12, on the spot (no message); a score can never exceed the criterion's maximum.
  - Step 3: the score preview reads **24/30**.
  - Step 4: the student's row in the list now reads **Graded 24/30**. ("Grade submitted successfully." is the server's reply, not text on the page.)
  - Step 5: student3 sees Graded, 24/30 and the comment, on the assignment's page in Modules.
- **Already automated:** `tests/database/GradeWorkServiceTest.php`: `testGradingByRubricSumsTheCriteriaEachLimitedToItsMaximum`,
  `testOpeningSubmittedOrPartlyGradedWorkMovesItToGrading`, `testReopeningGradedWorkKeepsTheScoreButStopsItCounting`;
  `tests/database/AssessmentFeedbackServiceTest.php`; the comment on the item page:
  `tests/database/ModuleServiceTest.php::testAStudentSeesTheFeedbackOnAGradedAssignmentOnItsItemPage`
- **Playwright:** `tests/e2e/grading.spec.js`
- **Basis:** `GradeWorkService.php`, `DemoContentSeeder.php` (rubric), `DemoActivitySeeder.php` (student3's submission)
- **Review:** Confirmed by Shuay, October 7, 2026. Page wording clarified afterwards (see Note); the expected behavior is unchanged.
- **Note (October 7, found while automating it in a browser):** step 5 did not work: the instructor's comment was saved but the student's page in Modules said "No Feedback Yet" (the Assessments tab showed it). That was a bug and is fixed; the comment now shows on both pages.

### TC-GRD-02 Instructor grades a quiz's essay

- **Role:** Instructor, then Student. **Priority:** P1
- **Data:** `instructor1@mentora.test`, course IT101-A, Quiz 1. student2's attempt is Partially Graded: the auto-graded answers scored 9 of 13 (questions 1, 3 and 4 right, question 2 wrong), the 7-point essay waits. Afterwards `student2@mentora.test`.
- **Steps:**
  1. As the instructor open Quiz 1 in Modules, press View Submissions and choose student2 (Pedro Reyes).
  2. Press Grade Work. Grade the essay: first try 8 points, then 5.
  3. Press Submit Grade.
  4. Sign in as student2 and open Quiz 1.
- **Expected:**
  - Step 2: the essay's score box is editable as soon as grading starts, and the total so far is 9. Typing 8 shows the alert "Points must be between 0 and 7." and the total stays 9; typing 5 is accepted and the total follows: **14**.
  - Step 3: the attempt is **Graded** with **14 of 20** (the list row reads Graded 14/20). ("Grade submitted successfully." is the server's reply, not text on the page.)
  - Step 4: student2 now sees the full score: "Best score: 14/20".
- **Already automated:** `tests/database/GradeWorkServiceTest.php`: `testAWrittenQuestionIsGivenPointsAndTheTotalFollows`,
  `testPointsMustBeBetweenZeroAndTheQuestionsWorth`, `testAnAutoGradedQuestionIsCorrectedWithCorrectOrIncorrect`
- **Playwright:** `tests/e2e/grading.spec.js`
- **Basis:** `GradeWorkService.php`, `DemoActivitySeeder.php` (student2's Quiz 1 attempt)
- **Review:** Confirmed by Shuay, October 7, 2026. Page wording clarified afterwards (see Note); the expected behavior is unchanged.
- **Note (October 7, found while automating it in a browser):** step 2 did not work as described: after pressing Grade Work the essay's score box stayed greyed out and could not be typed in until the instructor selected the student again. That was a bug and is fixed (the page now redraws the student when grading starts).

### TC-GRD-03 Student sees their grades

- **Role:** Student. **Priority:** P1
- **Data:** `student1@mentora.test`, course IT101-A (all work graded except Lab 1, which is submitted and waiting; the grading scheme is published by default, to confirm on the seeded data)
- **Steps:** open IT101-A and its Grades.
- **Expected:**
  - The page shows the overall grade (**90%**, "So far, from your graded work"), points earned (**63/70**, "3 of 4 graded") and the standing **On track** (Passed or Failed is shown once the instructor finalizes the grades).
  - "How your grade is calculated" lists the categories, for example Quizzes 40%, 90%, 27 of 30 pts.
  - Each graded assessment shows its own score (Course Pre-Test 8/10, Quiz 1 19/20, Assignment 1 27/30) and Lab 1 says "Waiting for grade".
  - Draft assessments (such as "Quiz 2: Database Basics") do not appear.
  - If the instructor had not published the grading scheme, the final grade would be hidden (not seeded; covered by the PHP tests).
  - The instructor's gradebook shows the same final grade for the same student (90% for Dela Cruz, Juan).
- **Already automated:** `tests/database/GradeServiceTest.php`: `testAPublishedSchemeShowsTheCalculatedGradeAndBreakdown`,
  `testTheFinalGradeIsHiddenUntilTheSchemeIsPublished`, `testAnInstructorOverrideWins`; `tests/database/GradingEngineServiceTest.php`
- **Playwright:** `tests/e2e/grading.spec.js`
- **Basis:** `app/Services/Courses/GradeService.php`
- **Review:** Confirmed by Shuay, October 7, 2026. Concrete numbers added afterwards from the seeded data; the expected behavior is unchanged.

---

## Gaps this draft found

- **A wrong password had no automated test** (TC-AUTH-03): now covered by six tests in `AuthFlowTest`. Writing them showed that the login page told a visitor whether an email has an account; that is fixed (see the note on TC-AUTH-03).
- **Exact on-screen wording** is unconfirmed for every case marked so; the first run on staging should fix the text in the case, not the app.
- **Missing from P1 on purpose:** discussions, the calendar, resources, video progress, file uploads and the instructor's content authoring (creating modules, units, quizzes). They are P2 and need cases written.
- **A quiz or assignment was not completed when handed in** (TC-QUIZ-01, TC-ASG-01): the item stayed "Not Yet Completed" until the student pressed Mark as Completed. Found while automating TC-QUIZ-01 and fixed: handing it in now completes the item, and a migration (`CompleteItemsOfWorkAlreadyHandedIn`) completes the items of work handed in before the fix.
- **A student could not see the instructor's feedback on the assignment's page in Modules** (TC-GRD-01): the comment was saved, but that page said "No Feedback Yet" (only the Assessments tab showed it). Found while automating TC-GRD-01 and fixed.
- **A quiz's essay score box stayed disabled after pressing Grade Work** (TC-GRD-02): the instructor had to select the student again before typing the points. Found while automating TC-GRD-02 and fixed.
- **Two page routes crash.** Opening `/student/courses/<id>/<slug>/modules/items/<item id>` (and the instructor twin) answers with the error page: the route passes the controller three values but `ModuleController::viewLearningItem` takes two (found while automating TC-MOD-01). Nothing in the app links to that address (it uses `?item=` and the API), so only a typed or shared link hits it. Not fixed.
- **No requirements to check against.** Expected results such as "a late submission is allowed only when the assignment says so", or "a quiz with an essay shows Partially Graded until the essay is marked", are what the code does. Whether they are what the product should do is for the product owner to confirm.

---

## Wave 1: quiz rules (added 2026-10-28)

These cases come from the QA plan's Wave 1 (`docs/qa/coverage-matrix.md`, scenarios `PS-…`). Their expected results come from the decisions in `docs/qa/decision-register.md`, not only from the code. That is why some are **expected to fail until a defect is fixed**: the **QA status** line says which. None has been run yet. They carry their own priority, and several are P2 or P3 even though this file is the P1 file: they sit next to the quiz journeys they belong to.

QA status values: **FIX?** a fix is committed and a test pins it; expected to pass, not yet run. **REG** the defect was seen in the code; expected to fail until fixed. **REG?** a suspected defect; the first run confirms it. **NB** required but not built; expected to fail.

| ID | Case | Role | Priority | QA status |
|---|---|---|---|---|
| TC-QUIZ-04 | A quiz opened in a second tab resumes the same attempt | Student | P1 | FIX? |
| TC-QUIZ-05 | Between two tabs the last save wins, and nothing changes after the submit | Student | P1 | FIX? |
| TC-QUIZ-06 | An instructor enrolled as a student in another course follows the student's quiz rules there | Instructor | P1 | FIX? |
| TC-QUIZ-07 | Editing a quiz's questions does not change an attempt that is running | Instructor, Student | P1 | REG? |
| TC-QUIZ-08 | The attempt limit accepts 1 or more, or blank for unlimited, and refuses 0 and negatives | Instructor, Student | P2 | REG? |
| TC-QUIZ-09 | A quiz does not offer File Upload questions | Instructor | P2 | REG? |
| TC-QUIZ-10 | A cancelled attempt counts against the attempt limit (corrected after the baseline) | Student | P3 | VCI |
| TC-QUIZ-11 | A quiz with no questions cannot be published or started | Instructor, Student | P1 | REG |
| TC-QUIZ-12 | The server refuses impossible quiz settings and empty questions | Instructor | P1 | REG |
| TC-QUIZ-13 | A quiz password is asked before the attempt, and browser lockdown is shown | Instructor, Student | P2 | NB |
| TC-QUIZ-14 | A question import is judged by its content, not its file name | Instructor | P3 | NB |

### TC-QUIZ-04 A quiz opened in a second tab resumes the same attempt

- **Role:** Student. **Priority:** P1
- **Data:** `student5@mentora.test`, IT101-A, Quiz 1 (as TC-QUIZ-01; unlock it first by completing the two items before it)
- **Steps:**
  1. Open Quiz 1 and press Take Quiz. Answer question 1. Note the time left on the timer.
  2. Copy the address, open it in a second tab, and press Take Quiz (or Resume) there.
  3. In the second tab answer question 3. Go back to the first tab and reload it.
  4. Repeat the start with a fresh seed, this time pressing Take Quiz in both tabs at the same moment (two windows side by side, or the browser test's two pages).
- **Expected:**
  - Both tabs show the same attempt: the same attempt number ("1 of 2 attempts used" stays at one attempt in progress), and the same time left, give or take a second. The timer does not restart.
  - The answer from the first tab is already filled in on the second, and after the reload the first tab shows the answer given in the second.
  - Step 4: no error page and no "could not be started" message. Exactly one attempt is in progress. Only if the database itself refuses the start does the page say "The quiz could not be started. Reload the page to try again."
- **Already automated:** `tests/database/QuizTwoTabsTest.php::testASecondStartAtTheSameMomentResumesTheSameAttempt`; `QuizAttemptFailureTest::testAStartTheDatabaseRefusesSaysItCouldNotStart`
- **Playwright:** planned
- **Basis:** `QuizAttemptService::startAttempt` (unique quiz, student and attempt number; decision 15.1); commit `5140068`
- **QA status:** FIX? (PS-QUZ-05)
- **Review:** Draft. Expected result set by decision 15.1.

### TC-QUIZ-05 Between two tabs the last save wins, and nothing changes after the submit

- **Role:** Student. **Priority:** P1
- **Data:** as TC-QUIZ-04, with the attempt open in two tabs
- **Steps:**
  1. In tab A choose an answer for question 1. In tab B choose a different answer for the same question.
  2. In tab A press Submit Quiz and confirm.
  3. In tab B change the answer to question 2 and then press Submit Quiz.
  4. As `instructor1`, open IT101-A's Gradebook and student5's attempt.
- **Expected:**
  - Question 1 keeps tab B's answer (the later save). The attempt holds one answer per question, never two.
  - Step 3: tab B's change is refused with "This attempt is no longer in progress." and its submit with "This attempt has already been submitted." Nothing tab B sends after the submit changes the attempt.
  - The gradebook counts the attempt once: one score, one completion of the quiz item, attempts used = 1.
- **Already automated:** `QuizTwoTabsTest`: `testTwoSavesOfOneQuestionLeaveOneRowWithTheLast`, `testASaveAfterTheOtherTabSubmittedIsRefused`, `testASecondSubmitIsRefusedAndChangesNothing`, `testTheDatabaseKeepsOneAnswerPerQuestion`; `QuizAttemptFailureTest::testTheSameSubmitWithoutARefusalScoresCountsAndCompletes`
- **Playwright:** planned
- **Basis:** `QuizAttemptService::saveAnswer`, `submitAttempt`; migration `2026-11-16-000002_OneResponsePerQuestion`; decision 15.2; commit `5140068`
- **QA status:** FIX? (PS-QUZ-06)
- **Review:** Draft. Expected result set by decision 15.2.

### TC-QUIZ-06 An instructor enrolled as a student in another course follows the student's quiz rules there

- **Role:** Instructor. **Priority:** P1
- **Data:** `instructor2@mentora.test` (teaches IT102-A and IT201-A, not IT101-A). **Setup:** as the institution's administrator, enrol `instructor2@mentora.test` in IT101-A from the roster.
- **Steps:**
  1. As `instructor2`, open IT101-A. Open Quiz 1 before completing the items before it.
  2. Complete the two items, take Quiz 1 and submit it. Take it a second time and submit.
  3. Try a third attempt.
  4. As `instructor1`, set a quiz in IT101-A to Draft. As `instructor2`, try to open it by its address.
  5. As `instructor2`, open a quiz of IT201-A (which they teach).
- **Expected:**
  - In IT101-A they are a student: the quiz is locked until the items are done, the attempt limit of 2 stops the third attempt ("You've used all 2 attempts."), and the draft quiz is not found.
  - They see no preview, edit or grading controls in IT101-A.
  - In IT201-A they are still its instructor and preview the quiz as before.
- **Already automated:** `tests/database/QuizCourseRoleTest.php` (all five tests); `tests/database/CourseRoleInTest.php`
- **Playwright:** planned
- **Basis:** `CourseMembership::roleIn`, `QuizAttemptService::startAttempt`; decision 9.1; commit `5140068`
- **QA status:** FIX? (PS-QUZ-07)
- **Review:** Draft. Expected result set by decision 9.1.

### TC-QUIZ-07 Editing a quiz's questions does not change an attempt that is running

- **Role:** Instructor, then Student. **Priority:** P1
- **Data:** `student5`, `instructor1`, IT101-A, Quiz 1
- **Steps:**
  1. As `student5`, start Quiz 1 and answer question 1 with `"42"` (the correct answer). Leave the attempt running.
  2. As `instructor1`, open Quiz 1 in the quiz builder. Change question 1's correct answer to another option, and change its text.
  3. As `student5`, reload the attempt, then submit it.
  4. Start the second attempt.
- **Expected:**
  - The running attempt keeps the question as it was when it started: the same text, and it is scored against the answer key it started with, so question 1 still earns its 3 points.
  - The second attempt, started after the change, shows the new text and uses the new key.
  - The edit itself is not refused (the instructor may edit), and it does not alter results of attempts already submitted except through the instructor's own rescore.
- **Already automated:** none for a running attempt. `AssessmentRescoreService` rescoring skips attempts in progress.
- **Playwright:** planned
- **Basis:** decision AMB-ASM-03 (register Part I3): question edits must not change active attempts. Today questions are edited in place and the attempt reads the edited row (`QuestionService::updateQuestion`) [read in the code; not run].
- **QA status:** REG? (PS-QUZ-19) **Defect confirmed 2026-10-28** by a probe test failed against the decided behavior (`docs/qa/defects-2026-10-28.md`).
- **Review:** Draft. Expected result set by AMB-ASM-03.

### TC-QUIZ-08 The attempt limit accepts 1 or more, or blank for unlimited, and refuses 0 and negatives

- **Role:** Instructor, then Student. **Priority:** P2
- **Data:** `instructor1`, IT101-A. Create a quiz "Limit check" with one multiple-choice question and publish it.
- **Steps (boundary values for the attempt limit):** in the quiz settings save each value in turn and read the reply: `-1`, `0`, `1`, `2`, `99`, blank, `1.5`, `abc`. For `1`, `2` and blank, take the quiz as `student5` until refused.
- **Expected:**
  - `-1`, `0`, `1.5` and `abc` are refused with a message beside the field saying the limit must be a whole number of at least 1, or blank for unlimited (wording to confirm). The previous value stays.
  - `1`, `2` and `99` are saved. The student gets exactly that many attempts.
  - Blank means unlimited: the item says no limit, and a further attempt always starts while the quiz is open.
  - A new quiz saved without the field gets 1 attempt.
  - The same rules apply on create, on edit, and in a quiz import.
- **Already automated:** `QuizAttemptServiceTest::testTheDefaultLimitIsOneAttempt` (the default only)
- **Playwright:** no
- **Basis:** decision AMB-ASM-09 and MISS-ASM-01. Today nothing validates the field, and a blank (null) limit becomes 1, not unlimited (`QuizAttemptService` reads `attempt_limit ?? 1`) [read in the code; not run].
- **QA status:** REG? (PS-QUZ-14) **Defect confirmed 2026-10-28** by a probe test failed against the decided behavior (`docs/qa/defects-2026-10-28.md`).
- **Review:** Draft. Expected result set by AMB-ASM-09.

### TC-QUIZ-09 A quiz does not offer File Upload questions

- **Role:** Instructor. **Priority:** P2
- **Data:** `instructor1`, IT101-A, the quiz "Limit check" from TC-QUIZ-08, and an assignment in the same unit
- **Steps:**
  1. In the quiz builder open the list of question types.
  2. Send the add-question request for the quiz with the type `File Upload` directly (the browser's network tools, or the API test).
  3. Import a question file that contains a File Upload question into the quiz.
  4. In the assignment, check that file submission is still offered.
- **Expected:**
  - The quiz's type list does not contain File Upload.
  - The direct request and the import refuse the type with a message saying File Upload is not available in quizzes (wording to confirm). No question is added.
  - Assignments still accept file submissions.
- **Already automated:** none
- **Playwright:** no
- **Basis:** decisions AMB-ASM-08 and MISS-QZ-03. Today `QuestionService::createQuestion` accepts any type, and a student has no way to save a File Upload answer in a quiz [read in the code; not run].
- **QA status:** REG? (PS-QUZ-15) **Defect confirmed 2026-10-28** by a probe test failed against the decided behavior (`docs/qa/defects-2026-10-28.md`).
- **Review:** Draft. Expected result set by AMB-ASM-08.

### TC-QUIZ-10 A cancelled attempt counts against the attempt limit

*Corrected 2026-10-28 after the baseline run: the earlier expectation (a cancelled attempt does not count) contradicted `testACancelledAttemptStillUsesUpAChance`, which pins the code's behavior and passes. Under register rule I1.1 a behavior pinned by a test governs (register Part I4).*

- **Role:** Student. **Priority:** P3
- **Data:** `student5`, IT201-A, "Quiz: Keys and Relationships" (one attempt). **Setup:** the app has no screen that cancels an attempt. On the test database, give student5 one attempt of this quiz and set its status to `Cancelled` with SQL.
- **Steps:** as `student5`, open the quiz and press Take Quiz.
- **Expected:**
  - The cancelled attempt uses up the one chance: no new attempt starts, as when the limit is reached (TC-QUIZ-02).
  - The cancelled attempt stays in the record (it is not deleted).
  - The system never sets an attempt to Cancelled by itself. A staff action that cancels one, recording the reason, who did it and when, is required but not built (PS-QUZ-16).
- **Already automated:** `QuizAttemptServiceTest::testACancelledAttemptStillUsesUpAChance` (passed in the baseline of 2026-10-28)
- **Playwright:** no
- **Basis:** `QuizAttemptService::startAttempt` (counts every attempt that is not In Progress); register Part I4
- **QA status:** VCI (PS-QUZ-16); staff cancellation remains NB
- **Review:** Draft

### TC-QUIZ-11 A quiz with no questions cannot be published or started

- **Role:** Instructor, then Student. **Priority:** P1
- **Data:** `instructor1`, IT101-A, unit "Variables and Data Types"
- **Steps:**
  1. Add a new quiz "Empty quiz" and save it as a draft with no questions.
  2. Try to publish it.
  3. Add one multiple-choice question and publish it. Then delete the question.
  4. As `student5`, open "Empty quiz".
- **Expected:**
  - Step 1: saving an empty draft is allowed.
  - Step 2: publishing is refused with a message saying a quiz needs at least one question (wording to confirm). It stays a draft and students do not see it.
  - Step 3: publishing works with one question.
  - Step 4: a published quiz left with no questions can be started and submitted; it is graded with no percentage (`testAnEmptyQuizSubmitsAsGradedWithNoPercentage`, which passes, pins this; corrected after the baseline, register Part I4). Steps 2–3 still expect publishing to be refused.
- **Already automated:** none
- **Playwright:** planned
- **Basis:** decisions AMB-ASM-02 and MISS-ASM-01. Today `AssessmentService::publishedAssessment` and the status change in `updateAssessment` publish a quiz with no questions, and the page only says "This quiz has no questions yet." [read in the code; not run].
- **QA status:** REG (PS-ASM-03) **Defect confirmed 2026-10-28** by a probe test failed against the decided behavior (`docs/qa/defects-2026-10-28.md`).
- **Review:** Draft. Expected result set by AMB-ASM-02.

### TC-QUIZ-12 The server refuses impossible quiz settings and empty questions

- **Role:** Instructor. **Priority:** P1
- **Data:** `instructor1`, IT101-A, the quiz "Limit check". Send each change through the quiz settings, and again directly to `PATCH /instructor/api/courses/<id>/assessments/<id>` and the add-question request, so the browser's own checks are not what is tested.
- **Steps and expected (each row is refused, the message is beside the field, and nothing is saved):**

  | Change | Value |
  |---|---|
  | Close date before the open date | open tomorrow, close today |
  | Due date after the close date | due in 5 days, close in 3 |
  | Duration | `0`, `-5`, `abc` (accepted: `1`, blank for no time limit) |
  | Attempt limit | `0`, `-1` (see TC-QUIZ-08) |
  | Question points | `-1`, `1000000` (accepted: `0` to the upper limit; boundary values min−1, min, min+1, max−1, max, max+1 once the limit is set) |
  | Question text | empty, only spaces |
  | Question type | a type that does not exist, `File Upload` (TC-QUIZ-09) |

- **Expected (also):** a refused change leaves the quiz exactly as it was, with no half-saved fields. A valid change in the same form saves.
- **Already automated:** none
- **Playwright:** no
- **Basis:** decision MISS-ASM-01. Today `AssessmentService::updateAssessment` checks only the status, and `QuestionService::createQuestion` saves an empty text and any points [read in the code; not run]. The upper bound for points is not set by any decision: record what the code does once it is added.
- **QA status:** REG (PS-ASM-04) **Defect confirmed 2026-10-28** by a probe test failed against the decided behavior (`docs/qa/defects-2026-10-28.md`).
- **Review:** Draft. Expected result set by MISS-ASM-01.

### TC-QUIZ-13 A quiz password is asked before the attempt, and browser lockdown is shown

- **Role:** Instructor, then Student. **Priority:** P2
- **Data:** `instructor1`, `student5`, the quiz "Limit check"
- **Steps:**
  1. In the quiz settings turn on "Require a password" and set `Quiz-123`. Turn on browser lockdown. Save.
  2. As `student5`, open the quiz. Try to start it with no password, with `quiz-123`, then with `Quiz-123`.
  3. Turn the password off. As the student, start a new attempt.
  4. On the test database, read the stored password value.
- **Expected:**
  - The attempt starts only with the exact password. A wrong one is refused with a message, and no attempt is recorded.
  - With the password off, nobody is asked for one.
  - The stored value is a one-way hash, never the password itself, and the password never appears on a page or in an API reply.
  - With lockdown on, the student sees that lockdown is active before and during the attempt. Which restrictions lockdown enforces must be documented before this part can be judged.
- **Already automated:** none
- **Playwright:** no
- **Basis:** decision AMB-DB-02. Today the settings `require_password` and `browser_lockdown` are stored but nothing enforces them.
- **QA status:** NB (PS-ASM-12) **Not built: confirmed 2026-10-28** by not found in the code (`docs/qa/defects-2026-10-28.md`).
- **Review:** Draft. Expected result set by AMB-DB-02.

### TC-QUIZ-14 A question import is judged by its content, not its file name

- **Role:** Instructor. **Priority:** P3
- **Data:** `instructor1`, the quiz "Limit check", and four files: a real Aiken text file renamed to `.pdf`; a PNG image renamed to `.txt`; a real Moodle XML file; a scanned PDF (an image of a page, no text layer)
- **Steps:** import each file through the quiz's Import, read the preview, and cancel.
- **Expected:**
  - A file whose content does not match its name is refused before it is read, with a message that names the problem and shows no path or stack trace. No question is created.
  - The real Moodle XML file previews its questions as before.
  - The scanned PDF: when the question-builder service is running, its text is read (OCR) and previewed. When the service is not running, the import says the PDF has no readable text (record the message the code gives).
  - A refused or failed import never creates some of the questions.
- **Already automated:** the parsers have unit tests (`tests/unit/*Parser*Test.php`); content checks have none
- **Playwright:** no
- **Basis:** decision MISS-ASM-02 (register Part I3). Today the format is chosen by the file extension (`QuestionImportService::previewFile`).
- **QA status:** NB (PS-QBK-09) **Not built: confirmed 2026-10-28** by not found in the code (`docs/qa/defects-2026-10-28.md`).
- **Review:** Draft. Expected result set by MISS-ASM-02.

---

## Wave 2 (added 2026-10-28)

From the QA plan's Wave 2: the P1 scenarios of the critical and high-risk modules whose expected result is the code's behavior (**VCI**) or a recorded decision (**IBR**), expected to pass, and the P1 scenarios of the high-risk modules that are fixed (**FIX?**) or defects (**REG**). None has been run. Several planning scenarios (`PS-…`) share a case where they test one rule. A scenario that an existing case already covers is listed against that case in `docs/qa/wave-2-test-analysis.md` instead of being written again.

| ID | Case | Role | Priority | QA status |
|---|---|---|---|---|
| TC-AUTH-06 | Five failed sign-ins lock the email for fifteen minutes | Anonymous | P1 | VCI |
| TC-AUTH-07 | An idle session ends, and deactivating someone or signing them out everywhere ends theirs | Student, institutional administrator | P1 | VCI |
| TC-AUTH-08 | An invitation link works once, until it expires, and only while it is the latest | Invited person | P1 | VCI |
| TC-AUTH-09 | A password-reset link works once and ends the sessions the person had | Student | P1 | VCI |
| TC-AUTH-10 | A person with two roles works in the area of the role they chose, and only in roles they hold | Dean and instructor | P1 | VCI |
| TC-MOD-03 | Prerequisites: none, the previous item, or chosen items | Instructor, student | P1 | VCI |
| TC-MOD-04 | What completes each kind of item | Student | P1 | VCI |
| TC-MOD-05 | Deleting a module, unit or item takes what depends on it and keeps shared files | Instructor | P1 | VCI |
| TC-MOD-06 | Video and PDF uploads stop at their size and type limits; links accept web addresses only | Instructor | P1 | VCI |
| TC-MOD-08 | A quiz item's status and title stay in step with its quiz and its gradebook item | Instructor | P1 | VCI |
| TC-QUIZ-15 | Each question type is scored by its rule, and the attempt's status follows | Student | P1 | VCI |
| TC-QUIZ-16 | The answer key never reaches the student | Student | P1 | VCI |
| TC-QUIZ-17 | A running attempt survives the quiz closing; answers count until the timer and a short allowance | Student, instructor | P1 | VCI |
| TC-QUIZ-18 | Building a quiz: totals come from the questions; editing after attempts rescores the finished ones | Instructor | P1 | VCI |
| TC-QUIZ-19 | Importing questions: each format, a preview that writes nothing, and an undo | Instructor | P1 | VCI |
| TC-QUIZ-20 | Generating questions with AI: who may start it, the review tray, and who may act on it | Instructor | P1 | VCI |
| TC-QUIZ-21 | An id from another course answers "not found" on every assessment and grading route | Instructor | P1 | VCI |
| TC-ASG-03 | What a student may hand in, and the message for each reason they may not | Student | P1 | VCI |
| TC-ASG-04 | A resubmission after a grade is a new attempt, and the latest counts | Student, instructor | P1 | VCI |
| TC-GRD-04 | Scores stay within their limits, and grading moves through its states | Instructor | P1 | VCI |
| TC-GRD-05 | A graded score reaches the gradebook, and the final grade recalculates only on a published scheme | Instructor, student | P1 | VCI |
| TC-FIL-01 | Who may download each kind of file | Every role | P1 | VCI |
| TC-MOD-07 | A direct lesson link opens the course on that lesson, for every role | Every course role | P1 | FIX? |
| TC-GRD-06 | A chairperson's feedback on a submission is posted as staff | Chairperson | P1 | FIX? |
| TC-GRD-07 | Grading cannot be reopened or changed after the grades are finalized | Instructor | P1 | REG |

## Sign-in and sessions (Wave 2)

### TC-AUTH-06 Five failed sign-ins lock the email for fifteen minutes

- **Role:** Anonymous. **Priority:** P1
- **Data:** `student5@mentora.test` and `student4@mentora.test`; the platform's defaults (5 attempts, 15 minutes; Security settings)
- **Steps:**
  1. Enter a wrong password four times for student5, then the right one.
  2. Sign out. Enter a wrong password five times, then the right one.
  3. Try student4 with the right password.
  4. Wait until 15 minutes after the fifth failure and sign in with the right password. Then fail four more times and succeed.
  5. As the system administrator set "Failed sign-ins before a lockout" to 0 and fail ten times.
- **Expected:**
  - Step 1: below the limit the right password still works, and the success resets the count.
  - Step 2: after the fifth failure even the right password is refused: "Too many failed sign-in attempts. Try again after HH:MM." (15 minutes after the last failure).
  - Step 3: one email's failures never lock another.
  - Step 4: the lock ends at that time; a success starts the count again from zero.
  - Step 5: 0 turns the lockout off.
  - The lockout message never says whether the email has an account (see TC-AUTH-03).
- **Already automated:** `tests/database/SecurityPoliciesTest.php`: `testFewerFailuresThanTheLimitDoNotLock`, `testReachingTheLimitLocksUntilTheLengthHasPassedSinceTheLastFailure`, `testASuccessResetsTheCount`, `testOneEmailsFailuresDoNotLockAnother`, `testZeroAttemptsTurnsTheLockoutOff`; `tests/feature/SecuritySettingsTest.php::testALockedAccountCannotSignInEvenWithTheRightPassword`
- **Playwright:** planned
- **Basis:** `LoginLockoutFilter`, `app/Config/Platform.php` (`lockoutAttempts` 5, `lockoutMinutes` 15)
- **QA status:** VCI (PS-AUT-01, PS-AUT-18). Run PS-AUT-02 (the check that a success is recorded) first: decision AMB-AUTH-01 says it is.
- **Review:** Draft

### TC-AUTH-07 An idle session ends, and deactivating someone or signing them out everywhere ends theirs

- **Role:** Student, institutional administrator. **Priority:** P1
- **Data:** `student5`; `institution.admin@mentora.test`; the platform's idle limit (120 minutes; set it to 5 for the test)
- **Steps:**
  1. Sign in as student5 and leave the tab untouched for longer than the idle limit. Click a link. Repeat with a page script's request (the notification bell's request).
  2. Sign in again and use the site every few minutes for longer than the limit.
  3. Sign student5 in on two browsers. As the administrator, use "sign out everywhere" for student5. Use each browser, then sign in again on one.
  4. As the administrator, deactivate student5 while they are signed in. Use their browser. Try to sign in.
- **Expected:**
  - Step 1: the next request after the limit signs them out and sends them to the login page with a message (wording to confirm); a script's request gets a JSON 401 instead of a redirect.
  - Step 2: activity keeps the session alive, including the page's background polling (decision AMB-AUTH-04).
  - Step 3: both earlier sessions end on their next request; a session started after the sign-out is not affected.
  - Step 4: the session ends on the next request, and signing in is refused, even straight after a successful password.
  - Nobody else's session is touched.
- **Already automated:** `tests/feature/SecuritySettingsTest.php`: `testASessionIdleForLongerThanTheLimitIsSignedOut`, `testARecentlyActiveSessionStaysInAndIsRefreshed`; `tests/feature/AccountStateTest.php` (all)
- **Playwright:** planned
- **Basis:** the account-state and idle-timeout filters; `sessionTimeoutMinutes`
- **QA status:** VCI (PS-AUT-05, PS-AUT-06)
- **Review:** Draft

### TC-AUTH-08 An invitation link works once, until it expires, and only while it is the latest

- **Role:** Invited person (signed out). **Priority:** P1
- **Data:** invitations sent from People by `institution.admin@mentora.test` to new addresses (read the links in the mail sink)
- **Steps:**
  1. Open a link. Submit two passwords that differ, then a weak one, then nothing, then a good one.
  2. Sign in with the new password, and with any other.
  3. Open the same link again.
  4. Invite an address twice and open the first link. Revoke an invitation and open its link. Open a made-up link.
  5. Open a link just before it expires (move its expiry on the test database), and one just after.
- **Expected:**
  - Step 1: opening the form creates nothing; each bad password is refused with its reason and the link keeps working; a good one makes the account and sends the person to sign in.
  - Step 2: only the chosen password works.
  - Step 3: "This invitation has already been used. If you set your password, sign in with it."
  - Step 4: a replaced or revoked link is refused, and a made-up one is not found and never makes an account. A link of a deleted institution is refused. A problem page offers the sign-in page and says nothing about the institution.
  - Step 5: it works just before the expiry and is refused just after.
  - The invited person is held to the inviting institution's password rule (TC-IAA-08).
- **Already automated:** `tests/feature/InvitationLinkTest.php` (all); `tests/database/InvitationServiceTest.php`
- **Playwright:** `tests/e2e/system-admin.spec.js` (TC-SA-05, the single-use part)
- **Basis:** `InvitationController`, `InvitationService`
- **QA status:** VCI (PS-AUT-09, PS-AUT-18)
- **Review:** Draft

### TC-AUTH-09 A password-reset link works once and ends the sessions the person had

- **Role:** Student. **Priority:** P1
- **Data:** `student5`; a reset link sent from the forgot-password form or by an administrator (read it in the mail sink)
- **Steps:**
  1. Keep student5 signed in on one browser. On another, open the reset link, try mismatched and weak passwords, then set a good one.
  2. Use the first browser. Sign in with the old password, then the new one.
  3. Open the link again. Ask for a second link and open the first. Open a made-up link.
  4. Ask for a link, deactivate student5, then open it.
- **Expected:**
  - Step 1: bad passwords are refused with the reason and the link still works; a good one is set and the person is sent to sign in.
  - Step 2: the earlier session has ended; the old password no longer works, the new one does.
  - Step 3: a used or replaced link is refused; a made-up one is not found.
  - Step 4: a link sent before the account was deactivated no longer works.
  - Asking for a link answers the same whether the email has an account or not.
- **Already automated:** `tests/feature/PasswordResetLinkTest.php` (all)
- **Playwright:** planned
- **Basis:** `PasswordResetController`
- **QA status:** VCI (PS-AUT-10, PS-AUT-18)
- **Review:** Draft

### TC-AUTH-10 A person with two roles works in the area of the role they chose, and only in roles they hold

- **Role:** Dean and instructor (`dean2`); chairperson and instructor (`chairperson2`). **Priority:** P1
- **Data:** `dean2@mentora.test` / `Dean123!`; `chairperson2@mentora.test`
- **Steps:**
  1. Sign in as dean2. Switch to Instructor, open a page, then switch back to Dean.
  2. Send a switch to a role dean2 does not hold (student) directly.
  3. Sign out and back in.
  4. Sign in as chairperson2 for the first time.
  5. End dean2's dean assignment (test database), and use the site.
- **Expected:**
  - Step 1: each switch changes the area, menu and dashboard; nothing of the other role's area shows.
  - Step 2: refused: "You cannot switch to that role."
  - Step 3: the role chosen last is resumed.
  - Step 4: a chairperson who also teaches starts as chairperson.
  - Step 5: dean is dropped mid-session; a person with another role continues in it, and one with only the dean role reaches the explanation page.
- **Already automated:** `tests/database/ActiveRoleServiceTest.php` (all), `tests/feature/RoleSwitchTest.php` (all)
- **Playwright:** `tests/e2e/dean.spec.js` (TC-DEAN-02)
- **Basis:** `ActiveRoleService`
- **QA status:** VCI (PS-AUT-12)
- **Review:** Draft

---

## Modules and progress (Wave 2)

### TC-MOD-03 Prerequisites: none, the previous item, or chosen items

- **Role:** Instructor, then Student. **Priority:** P1
- **Data:** `instructor1`, `student5`, IT101-A, a unit with four published items A, B, C, D (and a draft item E between B and C)
- **Steps:**
  1. Set C to "previous item". As student5, open C before and after completing B (start B without finishing it first).
  2. Set D to "custom" with A and C, and also try to list D itself and a later item. Complete A only, then C.
  3. Set the first item A to "previous item".
  4. Set D to custom with nothing usable, then back to none.
  5. Move B after C.
  6. As `instructor1`, open every item; as student4, check that student5's progress unlocked nothing for them.
- **Expected:**
  - Step 1: C is locked until B is completed; starting B is not enough. The draft E is skipped as a prerequisite.
  - Step 2: D unlocks only when both A and C are done; a later item or the item itself is not kept in the list, and duplicates are dropped.
  - Step 3: refused: the first item has no previous item.
  - Step 4: a custom list with nothing usable becomes none; switching to none removes the stored list.
  - Step 5: prerequisites that now come later are pruned; an item left with none falls back to none.
  - Step 6: instructors are never locked; one student's progress never unlocks for another.
- **Already automated:** `tests/database/LearningProgressServiceTest.php` (the prerequisite tests); `ModuleServiceTest::testReorderingItemsPrunesPrerequisitesThatNowComeLater`
- **Playwright:** `tests/e2e/modules.spec.js` (TC-MOD-01, the previous-item part)
- **Basis:** `LearningProgressService`
- **QA status:** VCI (PS-MOD-02)
- **Review:** Draft

### TC-MOD-04 What completes each kind of item

- **Role:** Student. **Priority:** P1
- **Data:** `student5`, IT101-A: a reading, a PDF, a link, an uploaded video, a YouTube video, a video on another host, a quiz, an assignment and a discussion item
- **Steps:** open each item and do what it asks, checking after each step whether it reads as completed and whether the next item unlocks. For the uploaded video, watch to 50%, then 89%, then 90%; scrub back; send a progress of 150 and of −10 directly.
- **Expected:**
  - Reading, PDF and link complete when opened.
  - Uploaded and YouTube videos complete at 90% watched; progress only goes up and is held between 0 and 100; saving less never undoes a completion. A video on a host the app cannot track completes when opened.
  - A quiz and an assignment complete when handed in, even late and even while still being graded (TC-QUIZ-01, TC-ASG-01); a refused submission completes nothing.
  - A discussion item completes only after the student replies.
  - Completing twice keeps one record. A locked item, a draft item, or an item of another course cannot be completed, and neither can a dropped student or an instructor complete one.
  - Module progress counts only published items.
- **Already automated:** `LearningProgressServiceTest` (completion and video tests); `AssignmentSubmissionServiceTest::testHandingInAnAssignmentCompletesItsItem`; `QuizAttemptServiceTest::testHandingInAQuizCompletesItsItem`
- **Playwright:** `tests/e2e/modules.spec.js`
- **Basis:** `LearningProgressService`
- **QA status:** VCI (PS-MOD-04)
- **Review:** Draft

### TC-MOD-05 Deleting a module, unit or item takes what depends on it and keeps shared files

- **Role:** Instructor. **Priority:** P1
- **Data:** `instructor1`; a copy of IT101-A's content in IT101-B (build a module with two units, items with a PDF, a quiz and a discussion item; one PDF file used by two items)
- **Steps:**
  1. Delete one of the two items that use the shared PDF. Then the other.
  2. Delete the discussion item.
  3. Delete an item that another item waits on.
  4. Delete a unit, then a module in the middle of three.
  5. Check IT101-A.
- **Expected:**
  - Step 1: the file stays while another item uses it; deleting the last user removes it from the database and the disk.
  - Step 2: its thread stays as a general discussion of the course.
  - Step 3: the waiting item is released.
  - Step 4: everything under the unit or module goes, including the quizzes and their gradebook items; the remaining modules and units are renumbered with no gaps.
  - Step 5: nothing in another section is touched.
- **Already automated:** `tests/database/ContentDeletionServiceTest.php` (all)
- **Playwright:** no
- **Basis:** `ContentDeletionService`
- **QA status:** VCI (PS-MOD-06)
- **Review:** Draft

### TC-MOD-06 Video and PDF uploads stop at their size and type limits; links accept web addresses only

- **Role:** Instructor. **Priority:** P1
- **Data:** `instructor1`, IT101-B; files: MP4 of 499 MB, 500 MB and 501 MB; a `.mov`; a text file renamed `.mp4`; PDFs of 24.9 MB, 25 MB and 25.1 MB; a PNG renamed `.pdf`
- **Steps:**
  1. Upload each video into a video item, and cut one upload off halfway.
  2. Upload each PDF into a PDF item, then replace a PDF with another.
  3. Set a link item to `https://example.com`, `http://example.com`, `javascript:alert(1)`, `data:text/html,x` and `ftp://example.com`.
  4. On a plan whose storage is nearly full, upload a video that would pass it.
- **Expected:**
  - Videos up to 500 MB of an allowed type are saved; above it, or of another type, they are refused with the limit named; an interrupted upload says so ("The video could not be saved. Please try again." or the interrupted message, wording to confirm).
  - PDFs up to 25 MB that really are PDFs are saved; above it: "The PDF is larger than the 25 MB limit."; a disguised file is refused. Replacing a PDF removes the old file.
  - Only `http` and `https` links are saved; the others are refused.
  - The plan's storage limit refuses the upload with a message naming the limit.
- **Already automated:** `tests/database/ModuleServiceTest.php` (messages and link rule); no test uses real large files
- **Playwright:** no
- **Basis:** `ModuleService`
- **QA status:** VCI (PS-MOD-07, PS-MOD-08, PS-MOD-13). Exact boundary bytes (500 × 1024 × 1024) are the code's.
- **Review:** Draft

### TC-MOD-08 A quiz item's status and title stay in step with its quiz and its gradebook item

- **Role:** Instructor, then Student. **Priority:** P1
- **Data:** `instructor1`, IT101-B; a new quiz item "Step check" in a published unit, with one question
- **Steps:**
  1. Publish the item from its status chip in Modules. Open the quiz's own settings and the Gradebook.
  2. Rename the item; then save it with an empty title.
  3. Set the item back to draft; then to inactive.
  4. As `student5` (enrolled for the test), look for it after each step.
- **Expected:**
  - Step 1: publishing the item publishes its quiz and adds it to the gradebook.
  - Step 2: the new title follows to the quiz; an empty title does not rename it.
  - Step 3: drafting the item drafts the quiz; an inactive quiz item turns its quiz into a draft. Students see the quiz only while all of item, quiz, unit and module are published (TC-MOD-02).
- **Already automated:** `ModuleServiceTest`: `testPublishingAQuizItemPublishesItsAssessmentAndAddsItToTheGradebook`, `testDraftingOrRenamingAnItemFollowsToItsAssessment`, `testAnEmptyTitleDoesNotRenameTheAssessment`, `testAnInactiveQuizItemTurnsItsAssessmentIntoADraft`
- **Playwright:** no
- **Basis:** `ModuleService` (item status and title changes)
- **QA status:** VCI (PS-ASM-02)
- **Review:** Draft

## Quizzes (Wave 2)

### TC-QUIZ-15 Each question type is scored by its rule, and the attempt's status follows

- **Role:** Student. **Priority:** P1
- **Data:** `student5`; a quiz in IT101-B built for the test with: single choice (3 pts), multiple answer with two correct (4 pts), short answer with key `constant` (case-insensitive), essay (5 pts), and one question left with no correct choice. Two more quizzes: one with only choice questions, one with only an essay.
- **Steps:**
  1. Answer: the right choice; only one of the two correct answers; ` Constant ` with spaces and capitals; an essay. Leave one question unanswered. Submit.
  2. Repeat with all correct, and with the multiple answer given exactly.
  3. Take the choice-only quiz and the essay-only quiz.
- **Expected:**
  - Single choice is all or nothing. Multiple answer scores only the exact set (no partial credit). Short answer matches after case and space clean-up only. A question with no correct choice never scores. Unanswered scores 0.
  - With an essay the attempt is **Partially Graded** with the score so far; choice only is **Graded** at once; essay only is **Submitted**.
  - An empty quiz submits as Graded with no percentage (see TC-QUIZ-11 for why it should not be publishable).
- **Already automated:** `QuizAttemptServiceTest` (the scoring and status tests); `tests/unit/ShortAnswerGraderTest.php`
- **Playwright:** `tests/e2e/quizzes.spec.js` (TC-QUIZ-01)
- **Basis:** `QuizAttemptService::computeAutoScore`, `ShortAnswerGrader`; decision AMB-ASM-07
- **QA status:** VCI (PS-QUZ-03, PS-QUZ-04)
- **Review:** Draft

### TC-QUIZ-16 The answer key never reaches the student

- **Role:** Student. **Priority:** P1
- **Data:** `student5`, IT101-A, Quiz 1, with the browser's developer tools open (Network and page source)
- **Steps:** start the attempt; read the page source and every response while answering and after submitting; open the result page.
- **Expected:** no response or page carries `is_correct`, the accepted short answers, or the case setting while the attempt runs. After submitting, the student sees only what the result page is meant to show. Another student's attempt id answers "not found".
- **Already automated:** `QuizAttemptServiceTest::testStudentsOnlyGetTheirOwnAttempts`, `testNobodyElseCanAnswerForAStudent`
- **Playwright:** planned (assert on the network responses)
- **Basis:** `QuizAttemptService::startAttempt` (the student payload)
- **QA status:** VCI (PS-QUZ-08)
- **Review:** Draft

### TC-QUIZ-17 A running attempt survives the quiz closing; answers count until the timer and a short allowance

- **Role:** Student, instructor. **Priority:** P1
- **Data:** `student5`, `instructor1`; a quiz in IT101-B with a 5-minute duration, open now, `available_until` in 10 minutes, due in 3 minutes
- **Steps:**
  1. Start the attempt. As the instructor, close the quiz (status Closed).
  2. As the student, reload: the attempt resumes. Save an answer.
  3. Wait until the 5 minutes end. Save an answer 20 seconds after, and again 2 minutes after. Submit.
  4. Start a second quiz after its `available_until` has passed, and one after its due date with and without late attempts.
- **Expected:**
  - Steps 1–2: closing stops new attempts but a running one can be resumed and answered until its own timer ends.
  - Step 3: an answer within the allowance after the timer (60 seconds) is saved; after it: "Time is up for this attempt." Submitting is still accepted and scores only what was saved in time. Handed in after the due date, it is marked late.
  - Step 4: the availability messages of TC-QUIZ-03 ("This quiz closed on <date>.", "The due date has passed and late attempts aren't allowed."); with late attempts allowed it starts and is marked late.
  - A quiz with no time limit has no deadline. `due_date` never ends a running attempt.
- **Already automated:** `QuizAttemptServiceTest`: `testARunningAttemptCanBeResumedAfterTheQuizCloses`, `testAnswersAreAcceptedForAFewSecondsAfterTheTimerEndsToAllowForLatency`, `testNoAnswersAreAcceptedOnceTheTimeLimitHasPassed`, `testAnExpiredAttemptCanStillBeSubmittedButOnlyScoresWhatWasSavedInTime`, `testAnAttemptHandedInAfterTheDueDateIsMarkedLate`, `testAQuizWithoutATimeLimitHasNoDeadline`
- **Playwright:** no (timing)
- **Basis:** `QuizAttemptService` (`TIME_LIMIT_GRACE_SECONDS` = 60); decisions AMB-ASM-03, -04, -05 as recorded in register Part I
- **QA status:** VCI (PS-QUZ-01, PS-QUZ-02, PS-QUZ-09, PS-QUZ-10, PS-QUZ-11, PS-QUZ-13)
- **Review:** Draft

### TC-QUIZ-18 Building a quiz: totals come from the questions; editing after attempts rescores the finished ones

- **Role:** Instructor. **Priority:** P1
- **Data:** `instructor1`, IT101-B, a quiz with three questions (2 + 3 + 5 points), published, with one submitted attempt and one in progress
- **Steps:**
  1. Type a total of 50 in the quiz form and save. Add a question of 4 points. Duplicate one, reorder, delete one, clear all on a copy.
  2. Change the correct choice of a question the submitted attempt answered.
  3. Correct one answer by hand in Grade Work, then change that question's key.
  4. Delete a question. Check the gradebook item's maximum and the student's grade.
  5. With the gradebook's scheme as a draft, change a question.
- **Expected:**
  - Step 1: the total is always the sum of the questions (the typed 50 is ignored); duplicates sit after the original; the order follows the reorder.
  - Step 2: the submitted attempt is regraded; what the student chose is kept.
  - Step 3: a hand-graded answer is not regraded.
  - Step 4: the finished attempt is rescored against the new total; the gradebook item takes the new maximum and the grade follows. The attempt in progress is left alone (see TC-QUIZ-07).
  - Step 5: a draft scheme is flagged for recalculation instead of recalculated.
- **Already automated:** `tests/database/QuestionServiceTest.php` (all); `tests/database/AssessmentRescoreServiceTest.php` (all); `AssessmentServiceTest` (totals)
- **Playwright:** `tests/e2e/assessment-editor.spec.js` (untagged; name this case in it)
- **Basis:** `QuestionService`, `AssessmentRescoreService`
- **QA status:** VCI (PS-ASM-01, PS-ASM-07, PS-QBK-01)
- **Review:** Draft

### TC-QUIZ-19 Importing questions: each format, a preview that writes nothing, and an undo

- **Role:** Instructor. **Priority:** P1
- **Data:** `instructor1`, a quiz in IT101-B, and one sample file per format: docx, rtf, csv, xlsx, Aiken, GIFT, Moodle XML, QTI, plain text, a printed-exam PDF (samples under `tests/_support/fixtures/`, or made for the test)
- **Steps:**
  1. Import each file: read the preview, check the quiz did not change, and apply.
  2. Use another instructor's account (or another quiz) to apply the same preview.
  3. Apply it a second time. Wait over an hour and apply an unused preview.
  4. Undo an import within 10 minutes, and another after 10 minutes.
  5. On a quiz that students have taken, import a file that changes an answered question, then one that only adds questions.
  6. Import a file with a damaged question, a duplicate question and more questions than the limit.
- **Expected:**
  - Step 1: the preview lists the questions and writes nothing; applying adds exactly what was previewed, in the file's order.
  - Steps 2–3: the preview belongs to one person and one quiz; it applies once; after an hour it has expired ("This import has expired. Choose the file again.").
  - Step 4: undo restores the quiz as it was, within 10 minutes and by the same person only ("This import can no longer be undone." after).
  - Step 5: changing or removing an answered question needs a confirmation; adding does not.
  - Step 6: each problem is listed near its paragraph; a damaged or disguised file gets a sentence, not an error page; too many questions are refused. Applying and undoing are in the audit log.
- **Already automated:** `tests/database/QuestionImportHardeningTest.php` (all), `QuestionImportFormatsTest.php`, `QuestionImportPackagesTest.php`, `QuestionImportPlainDocumentTest.php`, `QuestionImportPrintedExamTest.php`, `QuestionImportExportTest.php`
- **Playwright:** no
- **Basis:** `QuestionImportService`, `ImportPreviewStore`
- **QA status:** VCI (PS-QBK-02, PS-QBK-04, PS-QBK-06)
- **Review:** Draft

### TC-QUIZ-20 Generating questions with AI: who may start it, the review tray, and who may act on it

- **Role:** Instructor. **Priority:** P1
- **Data:** `instructor1` and `instructor2` (add instructor2 as a second instructor of IT101-B); a quiz in a unit with a reading and a PDF; the question builder running with `QB_BACKEND=fake`
- **Steps:**
  1. Generate 5 questions from the unit's reading and PDF. Try once with the AI flag switched off for the institution, once at the plan's AI limit, once choosing an item of another unit, and once with nothing chosen.
  2. In the review tray edit a question, breaking a limit (empty text) and then validly. Accept one, discard one, replace one, top up two, accept all verified, discard all.
  3. As `instructor2`, open the same batch and try to accept or discard.
  4. Stop the worker partway through a batch, then start it again.
- **Expected:**
  - Step 1: generation starts only for a quiz of this course, with the flag on, within the plan limit, from this unit's readings and PDFs; nothing chosen means all of them; another unit's item is never read; the refusals say why.
  - Step 2: a bad edit changes nothing; accepting adds the question with its choices and key once; a discarded question cannot be accepted; accept-all-verified leaves out questions flagged for a look; discard-all keeps what was accepted. Accepting and cancelling are audited.
  - Step 3: only the creator of the batch may act on it.
  - Step 4: the batch shows it stalled, is put back from its checkpoint at most twice, then ends as lost; its results are applied once.
- **Already automated:** `tests/database/QuestionGenerationServiceTest.php`, `QuestionGenerationSafetyTest.php`, `AiJobServiceTest.php`, `tests/feature/AiWorkerEndpointsTest.php`
- **Playwright:** no
- **Basis:** `QuestionGenerationService`, `AiJobService`, the worker API
- **QA status:** VCI (PS-AIQ-01 to PS-AIQ-04, PS-AIQ-06, PS-AIQ-07)
- **Review:** Draft

### TC-QUIZ-21 An id from another course answers "not found" on every assessment and grading route

- **Role:** Instructor, student. **Priority:** P1
- **Data:** `instructor1` (IT101-A) and the ids of an assessment, submission, question, rubric, criterion and generated question in IT201-A (`instructor2`)
- **Steps:** as `instructor1`, call each assessment, question, rubric, grading and generation route of IT101-A with an id from IT201-A in place of its own. As `student2`, call the quiz and submission routes of IT101-A with ids of IT201-A.
- **Expected:** every call answers 404 (not 403 and not the other course's data). The same routes still work with IT101-A's own ids. A rubric of another section of the same course can still be copied; one of another course cannot.
- **Already automated:** `tests/feature/AssessmentRoutesScopeTest.php` (all)
- **Playwright:** no
- **Basis:** the `assessmentscope` filter, `AssessmentScopeService`
- **QA status:** VCI (PS-ASM-08, PS-GRD-08)
- **Review:** Draft

## Assignments and grading (Wave 2)

### TC-ASG-03 What a student may hand in, and the message for each reason they may not

- **Role:** Student. **Priority:** P1
- **Data:** `student5`, IT101-B (enrol student5 there for the test); an assignment whose settings and dates are changed between steps
- **Steps and expected (one reason at a time):**

  | Setting | Message on the assignment |
  |---|---|
  | Status Closed | "This assignment is closed." |
  | Not published | "This assignment isn't open for submissions yet." |
  | Before `available_from` | "Submissions open <date>." |
  | After `available_until` | "Submissions closed <date>." |
  | Past due, late not allowed | "The due date has passed and late submissions aren't allowed." |
  | One submission used of one | "You've already submitted this assignment." |
  | Three of three | "You've used all 3 submissions." |
  | No way to submit switched on | "Your instructor hasn't turned on a way to submit yet." |
  | Behind an unfinished prerequisite | the lock message of the item |

- **Then:** with everything open, submit nothing ("Add your work before submitting."); a link of `example.com` ("Enter a full link starting with http:// or https://."); a 20.1 MB file ("The file is larger than the 20 MB limit."); a 20 MB file, a text and a link together ("Your work was submitted."). Try as a student not enrolled ("Only students enrolled in this course can submit.").
- **Expected (also):** only the types switched on can be used; a refused submission saves nothing and completes nothing.
- **Already automated:** `tests/database/AssignmentSubmissionServiceTest.php` (all)
- **Playwright:** `tests/e2e/assignments.spec.js` (TC-ASG-01, TC-ASG-02)
- **Basis:** `AssignmentSubmissionService::blockingReason`, `submit`
- **QA status:** VCI (PS-ASG-01, PS-ASG-02, PS-ASG-06)
- **Review:** Draft

### TC-ASG-04 A resubmission after a grade is a new attempt, and the latest counts

- **Role:** Student, instructor. **Priority:** P1
- **Data:** IT101-A, student2 (assignment resubmitted after a grade in the seed); an assignment allowing three submissions
- **Steps:** as student5, submit, have `instructor1` grade it 70, resubmit, have it graded 85, then resubmit again and leave it ungraded. Press Submit twice quickly on the last one.
- **Expected:** each submission is a new numbered attempt; the latest **graded** one counts (85), the ungraded latest does not replace it until graded; the double press saves one attempt and the second press gets a friendly message, not an error; the item keeps one completion.
- **Already automated:** `AssignmentSubmissionServiceTest`: `testTheSubmissionLimitIsEnforcedAndNumbered`, `testTwoSubmitsWithTheSameAttemptNumberCannotBothBeSaved`, `testTheLatestGradedSubmissionIsTheOneThatCounts`, `testAResubmissionKeepsASingleCompletion`
- **Playwright:** no
- **Basis:** `AssignmentSubmissionService`, `AssessmentResultService`
- **QA status:** VCI (PS-ASG-03)
- **Review:** Draft

### TC-GRD-04 Scores stay within their limits, and grading moves through its states

- **Role:** Instructor. **Priority:** P1
- **Data:** `instructor1`, IT101-A: an assignment of 20 points with a rubric of three criteria (5, 5, 10), one without a rubric, one worth 0 points; Quiz 1 with student3's essay waiting
- **Steps:**
  1. Open a Submitted assignment in Grade Work. Note its status.
  2. Grade by points with −5, 25 and 17.5. Grade by rubric with criteria 6, blank, 10.
  3. Grade the 0-point assignment.
  4. Reopen the graded work, then grade it again.
  5. Grade the essay with −1, 8 (worth 7) and 6; correct an auto-graded answer as incorrect, then revert it.
  6. Open work that was never handed in.
- **Expected:**
  - Opening Submitted or Partially Graded work moves it to Grading.
  - Points are pulled into 0..20; a rubric caps each criterion at its maximum and counts a blank as 0; a 0-point assignment has no percentage; rubric grading without a rubric is refused.
  - Reopening returns it to Grading and takes the score out of the gradebook, which falls back to the earlier graded attempt; regrading replaces the score.
  - Essay points must be between 0 and the question's worth; correcting and reverting an auto-graded answer work; the gradebook follows only once the quiz is Graded.
  - Work never handed in cannot be graded.
- **Already automated:** `tests/database/GradeWorkServiceTest.php` (all)
- **Playwright:** `tests/e2e/grading.spec.js` (TC-GRD-01, TC-GRD-02)
- **Basis:** `GradeWorkService`
- **QA status:** VCI (PS-GRD-01, PS-GRD-02)
- **Review:** Draft

### TC-GRD-05 A graded score reaches the gradebook, and the final grade recalculates only on a published scheme

- **Role:** Instructor, student. **Priority:** P1
- **Data:** `instructor1`, IT101-A; student1 with two graded quiz attempts (70 and 90) and two graded assignment attempts (80 then 60)
- **Steps:** open the Gradebook. Ungrade a quiz attempt (reopen it). Set IT101-B's scheme to draft and grade work there. Enter a manual score, then clear it.
- **Expected:** a quiz counts its **best** graded attempt (90); an assignment its **latest** graded one (60); ungraded attempts are not copied, and a score is removed when nothing counts any more. A published scheme refreshes the student's final grade at once; a draft one does not. A manual score is stored with its percentage, overwritten by the next, and cleared by an empty value. The student sees the same numbers in Grades.
- **Already automated:** `tests/database/GradebookSyncServiceTest.php` (all)
- **Playwright:** `tests/e2e/grading.spec.js` (TC-GRD-03)
- **Basis:** `GradebookSyncService`
- **QA status:** VCI (PS-GRD-07)
- **Review:** Draft

## Files (Wave 2)

### TC-FIL-01 Who may download each kind of file

- **Role:** every role. **Priority:** P1
- **Data:** in IT101-A: a published PDF, a draft PDF, a PDF behind an unfinished prerequisite, student1's assignment file, an announcement attachment, a discussion attachment, a generation source file. Accounts: student1 (owner), student5 (enrolled), a student who dropped IT101-A, `instructor1`, `instructor2` (another section), `chairperson1` (IT), `chairperson2` (unassigned), `dean2` (CBM), `dean1` (another department), `parent1`, the institution's administrator, a person of another institution. Get each file's download address as `instructor1`.
- **Steps:** as each account, open each address.
- **Expected:**

  | File | May download | Refused (not found) |
  |---|---|---|
  | Published resource | its instructors, enrolled students, chairperson of IT, dean of CBM | dropped student, other section's instructor, other chair, other dean, other institution |
  | Draft or locked resource | its instructors, chairperson, dean | every student |
  | Submission file | the student who handed it in, the course's instructor(s) | other students, `dean2` acting as dean, other instructors |
  | Announcement or discussion attachment | course members | non-members |
  | Generation source | the course's instructors | students |
  | Any file | the owner always | a parent, always; anyone for a missing, inactive or deleted file |

  `dean2` acting as the instructor of a section they teach opens its student work; as dean they gain no department-wide reach to student work. The institution's administrator opens course files read-only (wording of their view to confirm).
- **Already automated:** `tests/database/FileAccessServiceTest.php` (all); `tests/feature/ParentAuthorizationMatrixTest.php` (indirectly, for parents)
- **Playwright:** no
- **Basis:** `FileAccessService::canAccess`
- **QA status:** VCI (PS-FIL-01, PS-FIL-02, PS-FIL-03, PS-FIL-05). PS-FIL-05 (a submission file reached through an announcement attachment id) has no test: if it is served, that is a defect.
- **Review:** Draft

## Fixed since the last catalog review (Wave 2)

### TC-MOD-07 A direct lesson link opens the course on that lesson, for every role

- **Role:** Student, instructor, chairperson, dean, institutional administrator. **Priority:** P1
- **Data:** IT101-A's lesson "What Is a Variable?" (published) and a draft lesson; a deleted lesson's id; a lesson of IT201-A
- **Steps:** as each role, type the lesson's address (`/<area>/courses/<id>/<slug>/modules/items/<item id>`). As the student, open the draft lesson's address. As each, open the deleted lesson's address, IT201-A's lesson through IT101-A's address, and a course out of their reach.
- **Expected:** each role lands on the course page with that lesson open (never the error page); for the dean and the administrator it is read-only and a draft is marked as a draft; a student's link to a draft lesson, a deleted lesson, another course's lesson and a course out of reach all answer "not found".
- **Already automated:** `tests/feature/ReadOnlyLessonLinkTest.php` (all), `CourseMembershipTest::testTheLearningItemAddressOpensTheModulesInsteadOfFailing`, `tests/unit/RouteHandlersTest.php`
- **Playwright:** planned
- **Basis:** `app/Config/Routes.php` (the lesson routes now go to `CourseController::view`); decisions 5.1–5.3; commit `2154a2f`. This settles the "Two page routes crash" known issue above (register PLN-04): update that note once this case passes.
- **QA status:** FIX? (PS-MOD-10, PS-MOD-11)
- **Review:** Draft

### TC-GRD-06 A chairperson's feedback on a submission is posted as staff

- **Role:** Chairperson. **Priority:** P1
- **Data:** `chairperson1` (IT; does not teach IT101-A); student1's graded assignment in IT101-A
- **Steps:** open the submission's feedback as the chairperson and post a comment. As student1, read it and reply. As `chairperson2` (another sub-department), try to post on it.
- **Expected:** the chairperson posts as staff of the course (not refused as "You can only reply to your own submission."); the student sees it as from staff; a chairperson of another sub-department cannot reach the submission.
- **Already automated:** `tests/database/CourseRoleChecksTest.php`, `CourseRoleInTest.php`
- **Playwright:** no
- **Basis:** `AssessmentFeedbackService` (staff by `roleIn`: instructor or chairperson); decisions 9.1, 9.4; commit `5140068`
- **QA status:** FIX? (PS-GRD-05)
- **Review:** Draft

### TC-GRD-07 Grading cannot be reopened or changed after the grades are finalized

- **Role:** Instructor. **Priority:** P1
- **Data:** `instructor1`, IT101-B with one graded assignment, grades finalized
- **Steps:** in Grade Work, reopen the graded assignment; send the reopen request directly; change its score. Reopen the finalization with a reason and try again.
- **Expected:** while finalized, reopening and grading are refused with "The grades are finalized. Reopen them to make changes."; the frozen final grade does not change. After the finalization is reopened, both work.
- **Already automated:** none for these two actions
- **Playwright:** no
- **Basis:** decision AMB-ASG-06. Today `GradeWorkService::reopenGrading` and `submitGrade` do not check finalization; the frozen `final_percentage` still protects the final grade [read in the code; not run].
- **QA status:** REG (PS-GRD-06) **Defect confirmed 2026-10-28** by a probe test failed against the decided behavior (`docs/qa/defects-2026-10-28.md`).
- **Review:** Draft

---

## Wave 3 (added 2026-10-28)

From the QA plan's Wave 3: the P2 scenarios, and the P1 scenarios of medium-risk modules that no earlier wave took (discussions, announcements, calendar). QA status as in the README: **VCI** and **IBR** are expected to pass; **FIX?** a committed fix, expected to pass; **REG** a defect seen in the code and **REG?** a suspected one, expected to fail until fixed; **NB** not built, expected to fail. None has been run.

| ID | Case | Role | Priority | QA status |
|---|---|---|---|---|
| TC-AUTH-11 | The emailed sign-in code for other roles, for several roles, and when mail fails | Instructor, dean | P2 | VCI, IBR |
| TC-AUTH-12 | A person with no usable role gets an explanation, not a crash | Anonymous | P2 | VCI |
| TC-AUTH-13 | A signed-in person changes their own password, also in a read-only institution | Student | P2 | NB |
| TC-MOD-09 | The Resources tab lists the course's files and links by module and unit | Instructor, student | P2 | VCI |
| TC-MOD-10 | A quiz, assignment or discussion item brings its own assessment or thread | Instructor | P2 | VCI |
| TC-QUIZ-22 | Rubrics: building one, reusing one, and the assignment's total following it | Instructor | P2 | VCI |
| TC-QUIZ-23 | An assessment reads Closed by its dates, at the code's boundary second | Instructor, student | P2 | IBR |
| TC-QUIZ-24 | Question import limits, and what the checker calls an error or a warning | Instructor | P2 | VCI |
| TC-QUIZ-25 | Printing a quiz: versions, shuffles, the key, and paper size | Instructor | P2 | VCI |
| TC-QUIZ-26 | Generating questions without the job queue, and what the provider is sent | Instructor | P2 | VCI, IBR |
| TC-QUIZ-27 | A generation request asks for 1 to 50 questions, and only questions made count toward the allowance | Instructor | P2 | REG? |
| TC-QUIZ-28 | Generated questions are measured against a recorded baseline before release | (QA, AI owner) | P2 | NB |
| TC-ASG-05 | Assignment uploads accept only the allowed file types | Student | P2 | NB |
| TC-GRD-08 | The feedback thread on a piece of work | Instructor, student | P2 | VCI |
| TC-DSC-01 | Discussions: titles, replies, attachments, unread marks and filters | Instructor, student | P2 | VCI |
| TC-ANN-03 | Announcement attachments, replies and reactions | Instructor, student | P2 | VCI |
| TC-ANN-04 | An author with two roles appears once per post in the course feed | Student | P2 | REG? |
| TC-ANN-05 | A course announcement can be a draft, scheduled or published | Instructor, student, parent | P2 | NB |
| TC-ANN-06 | Students and instructors are told of a new course announcement | Student, instructor | P2 | NB |
| TC-CAL-01 | The calendar's date range, and the student's schedule page | Student, instructor | P2 | VCI |
| TC-NOT-01 | Marking one notification read never marks the others | Student | P2 | REG? |
| TC-FIL-02 | How a file is served: ranges, inline or download, and the logo | Every role | P2 | VCI |
| TC-STU-01 | The student dashboard and profile | Student | P2 | VCI |
| TC-FE-01 | Every screen not yet checked passes the accessibility check, fits three widths, and works by keyboard | Student, instructor, chairperson, parent | P2 | VCI |
| TC-DSC-02 | Who may read, post and moderate a course discussion | Every course role | P1 | VCI, FIX? |
| TC-ANN-07 | Who may change a course announcement, and the Announcements app | Instructor, student | P1 | VCI |
| TC-ANN-08 | An announcement posted from the calendar for a later day stays hidden until that day | Instructor, student, parent | P1 | REG? |
| TC-CAL-02 | What the calendar shows each person, and their reminders | Student, instructor, chairperson | P1 | VCI, IBR |

### TC-AUTH-11 The emailed sign-in code for other roles, for several roles, and when mail fails

- **Role:** Instructor, dean (`dean2`, also an instructor). **Priority:** P2
- **Data:** platform Security settings › roles that need a code; institution 1's own setting; the mail sink
- **Steps:**
  1. Add "instructor" to the platform's roles. Sign in as `instructor1`: password, then the code from the mail sink. Enter a wrong code first.
  2. Remove it; as institution 1 add "dean". Sign in as `dean2` while their active role is Instructor.
  3. Stop the mail sink in a non-development environment and sign in as a role that needs a code. Repeat in development and read the log.
  4. Try to remove "admin" from the roles (page and by hand-editing the setting).
- **Expected:**
  - Step 1: a chosen role needs the code after the password; a wrong code does not sign in; signing out means the code is asked again.
  - Step 2: a person holding any chosen role gets a code, whichever role is active (decision AMB-AUTH-07).
  - Step 3: outside development a mail failure stops the sign-in with a message; in development the code is written to the log.
  - Step 4: administrators always need a code.
- **Already automated:** `tests/feature/AdminTwoFactorTest.php`, `tests/database/SecurityPoliciesTest.php` (role choice, unit level), `InstitutionSecurityTest::testTheEmailedCodeAppliesToTheRolesTheInstitutionAddedForItsOwnPeople`
- **Playwright:** no
- **Basis:** the two-factor action, `twoFactorRoles`
- **QA status:** VCI (PS-AUT-07, PS-AUT-08), IBR (PS-AUT-20)
- **Review:** Draft

### TC-AUTH-12 A person with no usable role gets an explanation, not a crash

- **Role:** a test account in no group. **Priority:** P2
- **Steps:** create an account with no role on the test database; sign in.
- **Expected:** a 403 page explains there is no area to open; no error page. A dean with no department and no other role reaches the dean explanation page instead (TC-AUTH-10).
- **Already automated:** `AuthFlowTest::testSomeoneWithoutAnyRoleGetsAForbiddenPageNotACrash`
- **Playwright:** no
- **Basis:** `RedirectController`
- **QA status:** VCI (PS-AUT-13)
- **Review:** Draft

### TC-AUTH-13 A signed-in person changes their own password, also in a read-only institution

- **Role:** Student. **Priority:** P2
- **Data:** `student5`; institution 1 active, then read-only
- **Steps:**
  1. Find "Change password" in the profile or account menu. Change it with a wrong current password, a new one below the institution's minimum, two that do not match, then validly.
  2. Sign in with the old and the new password.
  3. Make the institution read-only and change the password again.
  4. Signed out, use "Forgot password" for student5 and for an unknown email.
- **Expected:**
  - Step 1: the current password is required; the policy is enforced with a reason beside the field; a valid change is saved with the framework's hashing.
  - Step 2: only the new password works.
  - Step 3: allowed: it is an account-security action, not an institution record (decision DOC-16).
  - Step 4: the reset link flow works as TC-AUTH-09, and answers the same for an unknown email.
- **Already automated:** none (the reset link: `PasswordResetLinkTest`)
- **Playwright:** no
- **Basis:** decisions MISS-AUTH-01 and DOC-16. Today there is no change-password page [read in the code; not run].
- **QA status:** NB (PS-AUT-17, PS-ROM-03) **Not built: confirmed 2026-10-28** by not found in the code (`docs/qa/defects-2026-10-28.md`).
- **Review:** Draft

### TC-MOD-09 The Resources tab lists the course's files and links by module and unit

- **Role:** Instructor, student. **Priority:** P2
- **Data:** IT101-A; a draft module, a draft unit and a draft item; a PDF behind an unfinished prerequisite
- **Steps:** as each role open Resources: the top level, a module, a unit; search, sort by name and by course order, filter by type, switch list and grid; open a file and a link. As a dropped student and an outsider, open it.
- **Expected:** the top level lists modules and a module lists its units; students do not see drafts at any level; a unit lists its files and links; an empty unit says so; search also matches a resource's own title; only `list` or `grid` is accepted as a view; a file has protected view and download addresses and a label, a link opens its address and has no download or size; a locked resource has no view or download address for a student until the prerequisite is done; instructors are never locked; outsiders and dropped students are turned away; a module or unit of another section is not found.
- **Already automated:** `tests/database/ResourceServiceTest.php` (all)
- **Playwright:** no
- **Basis:** `ResourceService`
- **QA status:** VCI (PS-MOD-16)
- **Review:** Draft

### TC-MOD-10 A quiz, assignment or discussion item brings its own assessment or thread

- **Role:** Instructor. **Priority:** P2
- **Data:** IT101-B, a unit
- **Steps:** add a quiz item, an assignment item and a discussion item; edit the discussion item's prompt and only its status; delete each item; delete a quiz from Assessments that has an item.
- **Expected:** each item creates its assessment or thread; editing the discussion item keeps its thread in step, and a status-only change leaves the thread alone; deleting an item deletes its assessment too; deleting the discussion item keeps its thread as a general discussion; deleting an assessment deletes its item.
- **Already automated:** `ModuleServiceTest` (`testAQuizItemBringsItsOwnAssessment`, `testADiscussionItemBringsItsOwnThread`, `testEditingADiscussionItemKeepsItsThreadInStep`, `testChangingOnlyTheStatusLeavesTheThreadAlone`), `ContentDeletionServiceTest`
- **Playwright:** no
- **Basis:** `ModuleService`, `ContentDeletionService`
- **QA status:** VCI (PS-MOD-15). The planning scenario said deleting the item leaves the assessment attached to the course; the code deletes it, and the code governs.
- **Review:** Draft

### TC-QUIZ-22 Rubrics: building one, reusing one, and the assignment's total following it

- **Role:** Instructor. **Priority:** P2
- **Data:** `instructor1`; an assignment in IT101-A, another in IT101-B, one in IT201-A (`instructor2`)
- **Steps:** create a rubric with three criteria; create one with no details; add and remove criteria; open the library from another assignment of IT101-A, select a rubric, edit the copy; try to select IT201-A's rubric; archive a rubric; delete one.
- **Expected:** criteria are kept in order and their points add up to the assignment's total, whatever changes the rubric; a rubric with no details gets a default title; creating or selecting replaces the assessment's current rubric; selecting copies the rubric and leaves the source alone; the library lists the course's rubrics except the assessment's own, never another course's, and leaves archived ones out; deleting takes the criteria with it.
- **Already automated:** `tests/database/RubricServiceTest.php` (all)
- **Playwright:** no
- **Basis:** `RubricService`
- **QA status:** VCI (PS-ASM-10)
- **Review:** Draft

### TC-QUIZ-23 An assessment reads Closed by its dates, at the code's boundary second

- **Role:** Instructor, student. **Priority:** P2
- **Data:** a quiz and an assignment in IT101-B with `available_until` set one minute ahead and `due_date` two minutes ahead
- **Steps:** read the status on the instructor's list and the student's item at 30 seconds before, at, and 30 seconds after `available_until`; then around `due_date`. Try to save the status "Closed" directly.
- **Expected:** Closed is worked out from the dates and the clock, not a stored choice: the item reads closed from the boundary second the code uses (record it: "at" or "after") and refuses new attempts or submissions from then on; around the due date only "late" changes. A request to store Closed by hand is not an independent state (decision AMB-ASM-01).
- **Already automated:** `QuizAttemptServiceTest::testAQuizPastItsAvailabilityWindowDoesNotStart`; `AssignmentSubmissionServiceTest::testSubmissionsAreRefusedWhenTheAssignmentIsNotOpen`
- **Playwright:** no
- **Basis:** `AssessmentService::quizAvailabilityReason`, `AssignmentSubmissionService::blockingReason`; decision AMB-ASM-01 (register Part I3)
- **QA status:** IBR (PS-ASM-05)
- **Review:** Draft

### TC-QUIZ-24 Question import limits, and what the checker calls an error or a warning

- **Role:** Instructor. **Priority:** P2
- **Data:** files of 4.9, 5 and 5.1 MB; pasted text of 199,999, 200,000 and 200,001 characters; files of 300 and 301 questions; an `.exe` renamed `.docx`; a `.tst` file; a scanned PDF; a file with a question repeated exactly, and one with two questions about 90% alike
- **Steps:** import or paste each, and read the preview.
- **Expected:** 5 MB, 200,000 characters and 300 questions are the limits: at the limit it is read, one over is refused; a refused extension and a `.tst` file are refused with a sentence; a scanned PDF is refused (or read through OCR when the question-builder service runs, TC-QUIZ-14); each rule's problem is listed as an error or a warning near its paragraph; an exact repeat is an error and a question at least 85% alike is flagged; warnings do not block.
- **Already automated:** `QuestionImportHardeningTest` (`testEachRuleCatchesItsProblemAsAnError`, `testAGoodQuestionHasNoProblemsAndWarningsDoNotBlock`, `testAFileTheSameQuestionTwiceIsAProblemAndALikeQuestionIsFlagged`, `testMoreQuestionsThanTheLimitAreRefused`, `testADamagedOrDisguisedFileIsASentenceNotAnError`)
- **Playwright:** no
- **Basis:** `QuestionImportValidator`, `QuestionImportService`
- **QA status:** VCI (PS-QBK-03, PS-QBK-05)
- **Review:** Draft

### TC-QUIZ-25 Printing a quiz: versions, shuffles, the key, and paper size

- **Role:** Instructor. **Priority:** P2
- **Data:** Quiz 1 of IT101-A; an empty quiz
- **Steps:** export as Word, RTF, PDF and HTML; one version, with and without shuffling; four versions; five versions; key only; Letter and an unknown paper size; print the same export twice. Export the empty quiz and an unknown format.
- **Expected:** the test numbers every question with its points and marks no answer; the key gives the right answer of every kind and says when a question has none; the key comes after a page break (Word), after the questions (RTF), on later pages (PDF); the Word file opens again; typed text is escaped in HTML; one version keeps the quiz order unless shuffling is asked; versions are letters, A being the quiz as written, at most four; each version's key follows its own shuffle, and the shuffle is the same every time; choices that point at each other ("all of the above") are not shuffled; key only leaves out the questions; an unknown paper falls back to A4; an empty quiz still prints saying so; an unknown format is refused.
- **Already automated:** `tests/database/PrintableTestExportTest.php` (all)
- **Playwright:** no
- **Basis:** `PrintableTestExport`
- **QA status:** VCI (PS-QBK-07)
- **Review:** Draft

### TC-QUIZ-26 Generating questions without the job queue, and what the provider is sent

- **Role:** Instructor. **Priority:** P2
- **Data:** jobs switched off (`useJobs=false`) with the legacy question-builder service running, then stopped; a unit with a reading, a PDF and a scanned PDF; a proxy or log that captures the request sent to the provider
- **Steps:** generate 5 questions from all three sources. Stop the legacy service and generate again. Read the captured request.
- **Expected:** with the service running, the scanned PDF is read through its OCR and the batch is made; with it stopped, the scanned PDF is skipped and named in a message, and the other sources still work. The request carries only the chosen source material and the generation settings: no student data, no names, emails or user ids (decision DOC-19).
- **Already automated:** `QuestionGenerationServiceTest` (`testChosenItemsThatCannotBeReadAreReportedByName`, `testAnUnavailableGeneratorFailsTheBatchWithItsReason`)
- **Playwright:** no
- **Basis:** `LocalQuestionBuilderClient`, `PdfTextExtractor`; decision DOC-19
- **QA status:** VCI (PS-AIQ-08), IBR (PS-AIQ-11)
- **Review:** Draft

### TC-QUIZ-27 A generation request asks for 1 to 50 questions, and only questions made count toward the allowance

- **Role:** Instructor. **Priority:** P2
- **Data:** a plan allowing 60 generated questions a term; the fake backend
- **Steps:** ask for 0, 1, 50 and 51 questions. Ask for 10 and let the backend make 6 (partial). Cancel a batch halfway. Top up 3 and replace 2. Read the allowance used after each.
- **Expected:** 0 and 51 are refused with a message; 1 and 50 start; a partial batch counts only the 6 made and says it was partial; a cancelled or failed batch counts only what was made; a top-up and a replacement count only the new questions. A question whose check could not run stays marked unverified and needs review.
- **Already automated:** none for the bounds or the accounting
- **Playwright:** no
- **Basis:** decision AMB-ASM-10. Today PHP has no upper bound on the count, and the accounting was not read [audit agent; not run].
- **QA status:** REG? (PS-AIQ-10) **Defect confirmed 2026-10-28** by reading the code (`docs/qa/defects-2026-10-28.md`).
- **Review:** Draft

### TC-QUIZ-28 Generated questions are measured against a recorded baseline before release

- **Role:** QA and the AI builder's owner (Shuay). **Priority:** P2
- **Data:** a version-controlled set of 25 cases (reading passages with the expected kinds of questions, a malformed output, a too-short source) and the provider settings in use
- **Steps:** run the set through the builder; record the provider, model, prompt version, set version, criteria and results; have the reviewer sign it off. Run the PHP-to-worker contract test with a missing field, a wrong type, a timeout and a worker failure.
- **Expected:** a question is accepted only when every check and save succeeds, with nothing half-saved; the baseline and its review are recorded with a date; the set passes the agreed criteria; the contract test catches each incompatible change before release.
- **Already automated:** none
- **Playwright:** no
- **Basis:** decision MISS-AI-01 (register Part I3)
- **QA status:** NB (PS-AIQ-09) **Not built: confirmed 2026-10-28** by not found in the code (`docs/qa/defects-2026-10-28.md`).
- **Review:** Draft

### TC-ASG-05 Assignment uploads accept only the allowed file types

- **Role:** Student. **Priority:** P2
- **Data:** an assignment in IT101-B allowing files; files: `.pdf`, `.docx`, `.xlsx`, `.pptx`, `.txt`, `.jpg`, `.png`, `.zip`, `.exe`, `.js`, a PDF renamed `.png`, an `.exe` renamed `.pdf`, a 20 MB and a 20.1 MB PDF
- **Steps:** submit each (also through the API).
- **Expected:** PDF, Word, Excel, PowerPoint, text, JPEG and PNG up to 20 MB are accepted; archives, programs and scripts are refused, and so is a file whose content does not match its name, with a clear message; over 20 MB: "The file is larger than the 20 MB limit."
- **Already automated:** the size limit only (`AssignmentSubmissionServiceTest`)
- **Playwright:** no
- **Basis:** decision MISS-ASG-02. Today the size is checked but there is no type allowlist [read in the code; not run].
- **QA status:** NB (PS-ASG-04) **Not built: confirmed 2026-10-28** by not found in the code (`docs/qa/defects-2026-10-28.md`).
- **Review:** Draft

### TC-GRD-08 The feedback thread on a piece of work

- **Role:** Instructor, student. **Priority:** P2
- **Data:** student5's submitted (ungraded) assignment in IT101-A, and student1's graded one
- **Steps:** as student5, post on their ungraded work. As the instructor, post on it, then grade it with a note; grade again with the same note, then a new one. As student5, reply; post on student1's work; post an empty message.
- **Expected:** the instructor may post before grading; the student can reply only once the work is graded and the instructor has written; a grading note adds an instructor message, the same note is not repeated (even after a student reply), a blank one adds nothing; nobody posts on someone else's submission; an empty message is refused.
- **Already automated:** `tests/database/AssessmentFeedbackServiceTest.php` (all)
- **Playwright:** no
- **Basis:** `AssessmentFeedbackService`
- **QA status:** VCI (PS-GRD-04)
- **Review:** Draft

### TC-DSC-01 Discussions: titles, replies, attachments, unread marks and filters

- **Role:** Instructor, student. **Priority:** P2
- **Data:** IT101-A Discussions; files of each allowed type, a disallowed type, and 25 MB and 25.1 MB files
- **Steps:**
  1. Start a thread with no title, a 255-character title and a 256-character one. Edit one to 300 characters. Reply empty, then with a long body.
  2. Attach each file.
  3. As student5, open the list: use each filter (all, pinned, mine, unanswered, modules, unread) and each sort; page through more than 10 threads; open a thread and come back.
- **Expected:** a title is required and at most 255 (create refuses longer, edit cuts it to 255); an empty reply is refused and a body has no length limit; allowed types up to 25 MB are kept, others refused with their message; unread marks clear on opening; filters and sorts work; 10 threads a page.
- **Already automated:** `tests/database/DiscussionServiceTest.php`
- **Playwright:** no
- **Basis:** `DiscussionService`
- **QA status:** VCI (PS-DSC-02, PS-DSC-04, PS-DSC-06)
- **Review:** Draft

### TC-ANN-03 Announcement attachments, replies and reactions

- **Role:** Instructor, student. **Priority:** P2
- **Data:** IT101-A; eleven small files; a file of another user (its id); a 25.1 MB file; student3 dropped from IT101-A for the test
- **Steps:** as `instructor1`, post with ten attachments, then eleven; attach another user's file by id; upload a 25.1 MB and a disallowed file; edit to remove two attachments; delete the post. As students, reply (empty and not), react twice with the same and a different emoji. As the dropped student, reply and react.
- **Expected:** up to ten attachments in order; the eleventh, another user's file, a too-large and a disallowed file are refused ("That file type is not allowed. Use images, PDFs, or office documents." for the type); removed attachments' files are deleted, and deleting the post removes its replies, reactions and files but never a file that is not the author's; any viewer except a dropped student may reply and react; one reaction per person; empty replies are refused.
- **Already automated:** `tests/database/AnnouncementServiceTest.php`
- **Playwright:** `tests/e2e/announcements.spec.js`
- **Basis:** `AnnouncementService`
- **QA status:** VCI (PS-ANN-02, PS-ANN-03)
- **Review:** Draft

### TC-ANN-04 An author with two roles appears once per post in the course feed

- **Role:** Student. **Priority:** P2
- **Data:** a person holding two roles (for example `dean2`, dean and instructor) who posts in a course they teach; a parent of a student in it
- **Steps:** as the author post one announcement; as a student open the course feed; as the parent open the course's announcements tab.
- **Expected:** the post appears exactly once in each, whatever the author's roles or groups.
- **Already automated:** none
- **Playwright:** no
- **Basis:** decision AMB-COM-03. Suspected: the feed joins the author's roles, which can repeat a post [audit agent; not run].
- **QA status:** REG? (PS-ANN-06) **Defect confirmed 2026-10-28** by a probe test failed against the decided behavior (`docs/qa/defects-2026-10-28.md`).
- **Review:** Draft

### TC-ANN-05 A course announcement can be a draft, scheduled or published

- **Role:** Instructor, student, parent. **Priority:** P2
- **Data:** IT101-A; `parent1`
- **Steps:** save an announcement as a draft; schedule another for tomorrow 08:00; post a third now. As a student and a parent, open the feed, the dashboard, the Announcements app, the parent tab and the calendar; open the draft's and the scheduled one's addresses directly. Move the clock past 08:00 and look again.
- **Expected:** the draft is seen only by its author and course staff; the scheduled one is hidden from students and parents everywhere (and by direct address) until its time, then appears and is treated as just posted; the author can edit both before then; the published one is seen at once. Notifications go out only on publication.
- **Already automated:** none
- **Playwright:** no
- **Basis:** decisions MISS-COM-01, AMB-COM-01. Today announcements have no draft or scheduled state.
- **QA status:** NB (PS-ANN-09) **Not built: confirmed 2026-10-28** by not found in the code (`docs/qa/defects-2026-10-28.md`).
- **Review:** Draft

### TC-ANN-06 Students and instructors are told of a new course announcement

- **Role:** Student, instructor. **Priority:** P2
- **Data:** IT101-A with two instructors (add `instructor2` for the test); a scheduled announcement (TC-ANN-05)
- **Steps:** post an announcement; edit it; publish the scheduled one when its time comes; make the mail fail once.
- **Expected:** each enrolled student and each other instructor gets one notification on publication (parents as their own rules say); editing does not notify again; a scheduled one notifies when it is published, not before; the links respect each person's access; a failed delivery is recorded and retried without duplicates.
- **Already automated:** none
- **Playwright:** no
- **Basis:** decision MISS-COM-02. Today only parents are told (`parents:notify`).
- **QA status:** NB (PS-ANN-10) **Not built: confirmed 2026-10-28** by not found in the code (`docs/qa/defects-2026-10-28.md`).
- **Review:** Draft

### TC-CAL-01 The calendar's date range, and the student's schedule page

- **Role:** Student, instructor. **Priority:** P2
- **Data:** `student5`; events on the first and last day of a month
- **Steps:** ask the events feed for a month (from the 1st to the 31st); ask with `from` after `to`, with a malformed date, and with none. Create a reminder valid and invalid; mark it done and delete it; try someone else's. Open the student Schedule page.
- **Expected:** the range includes both end days; an invalid range answers 400; a reminder is created (201) or refused (422); only the owner can mark or delete theirs, others get 404; the Schedule page opens with the student's class times (there is no route test for it today).
- **Already automated:** `tests/feature/CalendarRoutesTest.php`
- **Playwright:** no
- **Basis:** the calendar controllers, `ScheduleService`
- **QA status:** VCI (PS-CAL-02, PS-CAL-06)
- **Review:** Draft

### TC-NOT-01 Marking one notification read never marks the others

- **Role:** Student. **Priority:** P2
- **Data:** `student5` with five unread notifications
- **Steps:** mark one read from the full list. Send `POST notifications/read` with no id, with `abc`, with another user's id, and with an id that does not exist. Use "mark all read" if offered.
- **Expected:** only the chosen one is read; a missing or malformed id is refused and marks nothing; another user's notification is never touched; marking all is a separate, explicit action.
- **Already automated:** `NotificationsTest::testReadingAndCounting` (the normal path)
- **Playwright:** no
- **Basis:** decision AMB-COM-02. Suspected: a malformed id falls through to marking everything read [audit agent; not run].
- **QA status:** REG? (PS-NOT-04) **Defect confirmed 2026-10-28** by reading the code (`docs/qa/defects-2026-10-28.md`).
- **Review:** Draft

### TC-FIL-02 How a file is served: ranges, inline or download, and the logo

- **Role:** Every role. **Priority:** P2
- **Data:** a 10 MB video and a PDF in IT101-A; an HTML file uploaded as a resource; institution 1's logo; a person of another institution
- **Steps:** request the video with `Range: bytes=0-` and with a range past its end; open the PDF and the HTML file; add the download flag; read the headers. Open the logo as a member of institution 1, as a member of another institution, and signed out. Request a file you have no right to, and one that does not exist.
- **Expected:** a range gets at most a 2 MB slice; a range past the end is 416; only safe types open inline (an HTML file downloads); every response has `X-Content-Type-Options: nosniff` and private caching; the file name is safe even with quotes and accents; no right and no file look the same (404); signed out goes to login. The logo is visible to members of its institution only.
- **Already automated:** `tests/feature/FileRouteTest.php`; the logo has no test
- **Playwright:** no
- **Basis:** the file controller, `FileAccessService`
- **QA status:** VCI (PS-FIL-04, PS-FIL-07). If the logo opens for another institution's member, it is a defect.
- **Review:** Draft

### TC-STU-01 The student dashboard and profile

- **Role:** Student. **Priority:** P2
- **Data:** `student1` (two parents linked for the test: `parent1` and a second), `student4` (no parent), more than 8 open tasks and announcements and more than 6 reminders
- **Steps:** open the dashboard; open the profile; switch the parent portal off and open the profile again.
- **Expected:** the dashboard shows the student widgets: courses with progress (zero, not an error, for a course with no published content), at most 8 tasks soonest first with urgency (handed-in work is not overdue), at most 8 newest announcements across their courses, at most 6 pending reminders; the profile lists their parents by name, never by email; "No parent can see your progress." when none; with the portal off no parent is listed; nothing can be changed on it.
- **Already automated:** `tests/database/DashboardServiceTest.php`, `tests/feature/StudentProfilePageTest.php` (no page test for the dashboard)
- **Playwright:** no
- **Basis:** `DashboardService`, the student profile controller
- **QA status:** VCI (PS-STU-01, PS-STU-02)
- **Review:** Draft

### TC-FE-01 Every screen not yet checked passes the accessibility check, fits three widths, and works by keyboard

- **Role:** Student, instructor, chairperson, parent. **Priority:** P2
- **Data:** each role's screens: dashboard, courses, each course tab (Modules, Assessments, Discussions, Announcements, Resources, Grades), the quiz attempt, Grade Work, the gradebook, the calendar, the chairperson's Monitor Program and Override requests, the parent dashboard and child course
- **Steps:** on each screen, run the automated check (axe, WCAG 2 A and AA); view it at 360, 768 and 1440 pixels; then do its main task with the keyboard alone (open and close dialogs, use editors and menus, take a quiz).
- **Expected:** no serious or critical problem; no sideways scroll at any width; every task can be done by keyboard, focus is visible and returns to where it was after a dialog closes. (The dean's and the administrators' screens are TC-DEAN-09, 10, TC-IAA-04, 05, TC-SA-10.)
- **Already automated:** none for these screens
- **Playwright:** planned (as `dean.spec.js` TC-DEAN-10 does)
- **Basis:** the interface rules in `docs/`
- **QA status:** VCI (PS-FE-01, PS-FE-02, PS-FE-03)
- **Review:** Draft

### TC-DSC-02 Who may read, post and moderate a course discussion

- **Role:** Student, instructor, chairperson, dean, institutional administrator. **Priority:** P1
- **Data:** IT101-A Discussions; `student5` (enrolled), `student2`'s thread; a dropped student; `instructor2` (another section); `chairperson1` (IT, not teaching IT101-A); `dean2`; the institution's administrator
- **Steps:** as each, open the list and a thread, start a thread, reply, edit another's thread, delete another's reply, pin, lock, mark an answer.
- **Expected:** enrolled students and the course's instructor read and post; the chairperson posts and moderates as an instructor of the program's courses; the dean and the administrator read only; a non-enrolled or dropped student, another section's instructor and an outsider get not found; only the author edits; the author or the instructor deletes; only the instructor pins and locks; only the thread's author or the instructor marks an answer; a locked thread takes no replies until unlocked; moderation follows `discussions.manage` (TC-IAA-07).
- **Already automated:** `tests/database/DiscussionServiceTest.php`, `CourseRoleChecksTest.php`
- **Playwright:** no
- **Basis:** `DiscussionService`; decisions D6, 9.4; commit `5140068`
- **QA status:** VCI (PS-DSC-01), FIX? (PS-DSC-08)
- **Review:** Draft

### TC-ANN-07 Who may change a course announcement, and the Announcements app

- **Role:** Instructor, student. **Priority:** P1
- **Data:** IT101-A with a second instructor (`instructor2`, added for the test); posts by each; the Announcements app with department, institution and platform announcements
- **Steps:** as `instructor2`, edit and delete `instructor1`'s post, then pin it. As a student, use the instructor's post route; open another section's announcement. Open the Announcements app; search with two words; use `?level=` with a known and an unknown level; make the feed fail to load.
- **Expected:** only the author edits and deletes; any instructor of the section pins; a student cannot post; another section's announcement is not found; the app shows four levels in order; a search matches only posts containing all the words; an unknown level falls back to all; counts are shown before searching; a load failure shows an error, not an empty list.
- **Already automated:** `tests/database/AnnouncementServiceTest.php`, `tests/feature/AnnouncementRoutesTest.php`, `AnnouncementHelperTest.php`
- **Playwright:** `tests/e2e/announcements.spec.js` (TC-ANN-01, 02)
- **Basis:** `AnnouncementService`, the announcements app
- **QA status:** VCI (PS-ANN-01, PS-ANN-07)
- **Review:** Draft

### TC-ANN-08 An announcement posted from the calendar for a later day stays hidden until that day

- **Role:** Instructor, student, parent. **Priority:** P1
- **Data:** `instructor1`, IT101-A, the calendar; `student5`; `parent1`
- **Steps:** from the calendar post an announcement on a day three days ahead; try a day in the past. As the student look at the course feed, the dashboard, the Announcements app and the calendar; as the parent the course's announcements tab and notifications. Move the clock to that day and look again.
- **Expected:** a past day is refused; before the day nobody but the author sees it anywhere, and parents are not told; on the day it appears everywhere and parents are told once (TC-PRT-16).
- **Already automated:** `AnnouncementRoutesTest::testThePostFromTheCalendarLandsOnTheChosenDayAndRefusesThePast` (the date only)
- **Playwright:** no
- **Basis:** decision AMB-COM-01. Suspected: the post shows at once on every screen [audit agent; not run].
- **QA status:** REG? (PS-ANN-05) **Defect confirmed 2026-10-28** by a probe test failed against the decided behavior (`docs/qa/defects-2026-10-28.md`).
- **Review:** Draft

### TC-CAL-02 What the calendar shows each person, and their reminders

- **Role:** Student, instructor, chairperson. **Priority:** P1
- **Data:** IT101-A: a published quiz, a draft assignment, a quiz in a draft unit, an archived assessment, an assessment with no module, an announcement; `student5`, a dropped student, `instructor1`, `chairperson1`; a closed section; a read-only institution
- **Steps:** open the calendar as each. As the student, add a reminder (title of 150 and 151 characters, none, no date), tie it to IT101-A and to IT201-A, mark it done, delete it; as another student, open, mark or delete it. Make the institution read-only and add one; add one for the closed section.
- **Expected:** a student sees only published assessments in a published module, unit and item; the instructor and chairperson also see drafts, muted; archived and module-less assessments are left off; an announcement links to its course tab with an 80-character preview; a dropped student no longer sees the course; reminders are private to their owner (others get 404), need a title (at most 150) and a date, may be tied only to the person's own courses, are muted when done, and work in a read-only institution but not for a closed section.
- **Already automated:** `tests/database/CalendarEventServiceTest.php`, `ReminderServiceTest.php`, `ReadOnlyOwnThingsTest::testAStudentCanStillKeepTheirOwnReminders`
- **Playwright:** no
- **Basis:** `CalendarEventService`, `ReminderService`; decision 8.2
- **QA status:** VCI (PS-CAL-01), IBR (PS-CAL-03)
- **Review:** Draft

---

## Wave 4 (added 2026-10-28)

The P3 scenarios: small rules, rare paths and cosmetic checks. README: P3 is manual or covered by the PHP tests, so none is planned for Playwright. QA status as in the README; none has been run. The QA plan's exploratory charters and checks are not cases: they are in `docs/qa/exploratory-charters.md` and `docs/qa/checks.md`.

| ID | Case | Role | Priority | QA status |
|---|---|---|---|---|
| TC-AUTH-14 | "Remember me" and the idle limit | Student | P3 | IBR, VCI |
| TC-AUTH-15 | Lockout notice, sign-in methods, compromised passwords, and sessions | Student | P3 | NB |
| TC-MOD-11 | A course's participants and settings can be found | Instructor | P3 | IBR |
| TC-MOD-12 | Uploaded files are scanned for viruses before anyone can open them | Instructor, student | P3 | NB |
| TC-QUIZ-29 | Copying questions from another quiz of your own sections | Instructor, chairperson | P3 | VCI |
| TC-QUIZ-30 | A generation job that finishes while nobody is watching tells its teacher | Instructor | P3 | VCI |
| TC-ASG-06 | Only a student of the assignment's own section can submit to it (corrected after the defect check) | Student | P3 | VCI |
| TC-ANN-09 | The course feed's order, pages and search | Student | P3 | VCI |
| TC-ANN-10 | Only the four supported reactions are accepted | Student | P3 | REG? |
| TC-CAL-03 | The weekly schedule grid, and closed sections on the calendar | Student, instructor | P3 | VCI, IBR |
| TC-NOT-02 | The notification list and the bell panel's pages | Student | P3 | VCI |
| TC-NOT-03 | Marking read from the bell, and paging past 50 | Student | P3 | NB |
| TC-NOT-04 | A notification about a child who left opens a plain "not available" | Parent | P3 | IBR |
| TC-FIL-03 | Uploads abandoned partway are cleaned up | Instructor | P3 | NB |
| TC-FE-02 | Dark mode in every area | Every role | P3 | VCI |

### TC-AUTH-14 "Remember me" and the idle limit

- **Role:** Student. **Priority:** P3
- **Data:** `student5`; the idle limit set to 5 minutes for the test; a page that polls (the notification bell)
- **Steps:** sign in with "Remember me", close the browser, reopen it the next day (move the clock), and open a page. Sign in without it and leave a polling page open for 10 minutes, then click. Leave a non-polling page untouched for 10 minutes, then click.
- **Expected:** a polling page keeps the session alive (decision AMB-AUTH-04: background polling counts as activity); an untouched page is signed out after the limit. Remember-me: record how the 30-day remember-me and the idle limit combine in the code (Shield's `rememberLength`); no decision covers it, so the code's behavior is the expected result.
- **Already automated:** `SecuritySettingsTest::testARecentlyActiveSessionStaysInAndIsRefreshed`
- **Playwright:** no
- **Basis:** the idle-timeout filter, `app/Config/Auth.php`; decision AMB-AUTH-04
- **QA status:** IBR, VCI (PS-AUT-15)
- **Review:** Draft

### TC-AUTH-15 Lockout notice, sign-in methods, compromised passwords, and sessions

- **Role:** Student. **Priority:** P3
- **Steps and expected (each is decided in register Part I3):**
  1. Lock student5's email (TC-AUTH-06): the lock message is the notice; no email is sent and there is no administrator unlock.
  2. Look for any sign-in method other than email and password (plus the code where required): none exists, and the documentation no longer describes an "allowed methods" setting.
  3. Set a password known to be compromised (for example `Password123!`) through the reset link and the change-password page: refused with a message that does not say where the list came from. Cut the network to the lookup service and set one: accepted, and the failure logged. Existing passwords are not checked.
  4. Open the profile's sessions list: each session with device, last activity and sign-in time, no token; revoke one, and all others; the revoked sessions are refused on their next request. There is no limit on sessions.
- **Already automated:** none for 3 and 4
- **Playwright:** no
- **Basis:** decisions MISS-AUTH-04 to 07. Today parts 3 and 4 are not built.
- **QA status:** NB (PS-AUT-21) **Not built: confirmed 2026-10-28** by not found in the code (`docs/qa/defects-2026-10-28.md`).
- **Review:** Draft

### TC-MOD-11 A course's participants and settings can be found

- **Role:** Instructor. **Priority:** P3
- **Steps:** as `instructor1`, find the list of IT101-A's students and instructors, and the course's settings (description, grading, submission defaults), without typing an address.
- **Expected:** both can be reached from the course's own pages; a dedicated People tab or settings page is not required if another screen gives the same; changes are allowed only to those the server permits. If either cannot be found, record where it should be.
- **Already automated:** none
- **Playwright:** no
- **Basis:** decision MISS-CONT-01
- **QA status:** IBR (PS-MOD-17)
- **Review:** Draft

### TC-MOD-12 Uploaded files are scanned for viruses before anyone can open them

- **Role:** Instructor, student. **Priority:** P3
- **Data:** the EICAR test file (a harmless file every scanner flags) as a PDF item, an assignment upload and an announcement attachment
- **Steps:** upload it each way; try to open it as a student; stop the scanner and upload a clean file.
- **Expected:** the flagged file is rejected or quarantined and never served; while the scanner is down, files are not treated as clean (held or refused, with a message). Transcoding, thumbnails and duration extraction are not required.
- **Already automated:** none
- **Playwright:** no
- **Basis:** decision MISS-CONT-02. Not built.
- **QA status:** NB (PS-MOD-18) **Not built: confirmed 2026-10-28** by not found in the code (`docs/qa/defects-2026-10-28.md`).
- **Review:** Draft

### TC-QUIZ-29 Copying questions from another quiz of your own sections

- **Role:** Instructor, chairperson. **Priority:** P3
- **Steps:** in a quiz of IT101-A, open "Copy from another quiz": choose Quiz 1, tick two questions (one already in this quiz), copy. Try a quiz of `instructor2`'s section by id; copy from the quiz itself; copy nothing. As `chairperson1`, copy from a quiz of a section they chair.
- **Expected:** only quizzes with questions in my sections are offered; questions show their answers and a flag when already in the quiz; copies keep their choices, keys and settings, and the total follows; another teacher's quiz is never copied from and nothing changes; copying from the same quiz or with nothing chosen is refused; a chairperson may take from their sections; copying is audited.
- **Already automated:** `tests/database/QuestionPickerServiceTest.php` (all)
- **Playwright:** no
- **Basis:** `QuestionPickerService`
- **QA status:** VCI (PS-QBK-10)
- **Review:** Draft

### TC-QUIZ-30 A generation job that finishes while nobody is watching tells its teacher

- **Role:** Instructor. **Priority:** P3
- **Steps:** with jobs on, start a generation and close the browser; let the job finish; let another fail; make the worker slow to start.
- **Expected:** the finished job is applied once and the teacher is told, however often it is polled; a failed one tells them in plain words; a long wait says the builder is slow to start; settling twice tells once.
- **Already automated:** `tests/database/AiJobGenerationTest.php`
- **Playwright:** no
- **Basis:** `AiJobGeneration`
- **QA status:** VCI (PS-AIQ-12)
- **Review:** Draft

### TC-ASG-06 Only a student of the assignment's own section can submit to it

- **Role:** Student. **Priority:** P3
- **Data:** student5 enrolled in IT101-A only; an assignment of IT101-B (same course, another section)
- **Steps:** as student5, send the submit request for IT101-B's assignment directly, through IT101-A's address and through IT101-B's.
- **Expected:** for an assignment in a module, refused both ways ("Only students enrolled in this course can submit." or not found); nothing is saved; the service checks the section, not only the route filter. An assignment in no module accepts a student of any section of the course: the code states this as deliberate, so it governs (register Part I4).
- **Already automated:** `AssignmentSubmissionServiceTest::testOnlyEnrolledStudentsCanSubmit` (another course, not another section)
- **Playwright:** no
- **Basis:** `AssignmentSubmissionService::submit` ("A student of the assignment's section (9.1); one in no module falls back to any section of the course."); decision AMB-ASG-03 as limited by register Part I4
- **QA status:** VCI (PS-ENR-09), corrected 2026-10-28
- **Review:** Draft

### TC-ANN-09 The course feed's order, pages and search

- **Role:** Student. **Priority:** P3
- **Data:** IT101-A with 25 announcements, two pinned
- **Steps:** open the feed; scroll to load more; search a word; open a link to an old announcement (focus).
- **Expected:** pinned posts first, then newest first; 10 a page; the search narrows; a focus link loads enough pages to show its post; only this section's posts.
- **Already automated:** `AnnouncementServiceTest::testPinnedPostsLeadThenNewestFirst`, `testTheFeedOnlyHoldsThisOfferingsPosts`; `AnnouncementRoutesTest::testTheFocusAndSearchParametersReachTheFeed`
- **Playwright:** no
- **Basis:** `AnnouncementService`
- **QA status:** VCI (PS-ANN-04)
- **Review:** Draft

### TC-ANN-10 Only the four supported reactions are accepted

- **Role:** Student. **Priority:** P3
- **Steps:** react with 👍, ❤️, 😂 and 😮 from the page. Send directly: an empty value, `🎉`, `abc`, a 33-character string.
- **Expected:** the four are accepted; everything else is refused with "Pick a reaction." and nothing is stored.
- **Already automated:** none for the allowed set
- **Playwright:** no
- **Basis:** decision AMB-COM-08 (register Part I3). Today the server accepts any value up to 32 characters (`AnnouncementService::react`) [read in the code; not run].
- **QA status:** REG? (PS-ANN-11) **Defect confirmed 2026-10-28** by a probe test failed against the decided behavior (`docs/qa/defects-2026-10-28.md`).
- **Review:** Draft

### TC-CAL-03 The weekly schedule grid, and closed sections on the calendar

- **Role:** Student, instructor. **Priority:** P3
- **Data:** class times for student5's sections (TC-OFF-07), including a 20-minute class and two classes on one day; a completed section
- **Steps:** open Schedule as student5 and as `instructor1`; drop a course; open the calendar and the schedule after the section is closed, and follow a link to it.
- **Expected:** the grid runs Monday to Sunday in 30-minute rows from the earliest start to the latest end, rounded out to the hour; each class sits in its day and row with its label, time, room, mode and a link (the instructor's to their side of the site); a very short class still takes a row; only the person's own classes appear and a dropped course leaves the grid; "no courses" and "no classes" are told apart. A closed section is left out of the active schedule but stays on the calendar as history, marked completed; a link that can no longer open shows a plain "not available" message, not a raw 404 (decision AMB-COM-06).
- **Already automated:** `tests/database/ScheduleServiceTest.php` (all)
- **Playwright:** no
- **Basis:** `ScheduleService`, `CalendarEventService`
- **QA status:** VCI (PS-CAL-04), IBR (PS-CAL-05)
- **Review:** Draft

### TC-NOT-02 The notification list and the bell panel's pages

- **Role:** Student. **Priority:** P3
- **Data:** student5 with 60 notifications, some unread, some urgent
- **Steps:** open `/notifications`; open the bell and load more; send `?before=` with a count of 0 and of 100.
- **Expected:** the list shows the newest 50 with urgency and a message when empty; the bell loads 7 at a time and says when there are more; the count is held between 1 and 50; each person sees only their own; signed out goes to sign in.
- **Already automated:** `tests/feature/NotificationPagesTest.php`
- **Playwright:** no
- **Basis:** the notification controllers
- **QA status:** VCI (PS-NOT-03)
- **Review:** Draft

### TC-NOT-03 Marking read from the bell, and paging past 50

- **Role:** Student. **Priority:** P3
- **Steps:** mark one notification read from the bell panel; make the request fail once; open the full list and page to the 60th.
- **Expected:** it is marked read without leaving the panel and the unread count updates; a failure shows an error and does not pretend it was saved; the full list pages (or loads more) past the first 50, with a bounded query.
- **Already automated:** none
- **Playwright:** no
- **Basis:** decision MISS-COM-03. Not built.
- **QA status:** NB (PS-NOT-05)
- **Review:** Draft

### TC-NOT-04 A notification about a child who left opens a plain "not available"

- **Role:** Parent. **Priority:** P3
- **Steps:** as `parent1`, receive a notification about student5's grade; move student5 to having left the institution (test database); open the notification.
- **Expected:** the notification stays in the list; its link shows a plain unavailable or access-restricted message, not a raw 404 and not the record, and never another student's data.
- **Already automated:** none
- **Playwright:** no
- **Basis:** decision AMB-COM-07
- **QA status:** IBR (PS-NOT-08)
- **Review:** Draft

### TC-FIL-03 Uploads abandoned partway are cleaned up

- **Role:** Instructor. **Priority:** P3
- **Steps:** start a large video upload and close the tab halfway; wait over an hour; run the cleanup; check storage used and the file rows. Start another and keep it progressing past an hour.
- **Expected:** the abandoned `uploading` row and its partial data are removed after an hour, and its storage is released; a completed file and an upload still progressing are never touched; failures are recorded for retry.
- **Already automated:** none
- **Playwright:** no
- **Basis:** decision AMB-DB-03. Today nothing cleans these rows.
- **QA status:** NB (PS-FIL-06) **Not built: confirmed 2026-10-28** by not found in the code (`docs/qa/defects-2026-10-28.md`).
- **Review:** Draft

### TC-FE-02 Dark mode in every area

- **Role:** Every role. **Priority:** P3
- **Steps:** switch the system to dark mode; open each role's main screens (as in TC-FE-01) and the admin areas.
- **Expected:** every screen follows the theme with readable contrast; nothing stays white or unreadable; dialogs, tables and charts too.
- **Already automated:** none (the institution area: `institutional-admin.spec.js`)
- **Playwright:** no
- **Basis:** the theme tokens in `resources/css/app.css`
- **QA status:** VCI (PS-FE-04)
- **Review:** Draft
