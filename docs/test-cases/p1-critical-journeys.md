# P1 test cases: the critical journeys

17 P1 journey cases, and 11 quiz cases added in Wave 1 of the QA plan (2026-10-28, at the end of this file, each with its own priority). Read [README.md](README.md) first for the format, the demo accounts and
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
| TC-QUIZ-10 | A cancelled attempt does not count against the attempt limit | Student | P3 | REG |
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
- **QA status:** REG? (PS-QUZ-19)
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
- **QA status:** REG? (PS-QUZ-14)
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
- **QA status:** REG? (PS-QUZ-15)
- **Review:** Draft. Expected result set by AMB-ASM-08.

### TC-QUIZ-10 A cancelled attempt does not count against the attempt limit

- **Role:** Student. **Priority:** P3
- **Data:** `student5`, IT201-A, "Quiz: Keys and Relationships" (one attempt). **Setup:** the app has no screen that cancels an attempt. On the test database, give student5 one attempt of this quiz and set its status to `Cancelled` with SQL.
- **Steps:** as `student5`, open the quiz and press Take Quiz.
- **Expected:**
  - The item shows "0 of 1 attempt used", and a new attempt starts.
  - The cancelled attempt stays in the record (it is not deleted).
  - The system never sets an attempt to Cancelled by itself. A staff action that cancels one, recording the reason, who did it and when, is required but not built (PS-QUZ-16 in `docs/qa/coverage-matrix.md`).
- **Already automated:** none
- **Playwright:** no
- **Basis:** decision AMB-ASM-06. Today the limit counts every attempt that is not In Progress, Cancelled included (`QuizAttemptService::startAttempt`, `status != 'In Progress'`) [read in the code; not run].
- **QA status:** REG (PS-QUZ-16)
- **Review:** Draft. Expected result set by AMB-ASM-06.

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
  - Step 4: the student cannot start an attempt on a published quiz left with no questions. The page says why instead of opening an empty attempt, and no attempt is recorded.
- **Already automated:** none
- **Playwright:** planned
- **Basis:** decisions AMB-ASM-02 and MISS-ASM-01. Today `AssessmentService::publishedAssessment` and the status change in `updateAssessment` publish a quiz with no questions, and the page only says "This quiz has no questions yet." [read in the code; not run].
- **QA status:** REG (PS-ASM-03)
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
- **QA status:** REG (PS-ASM-04)
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
- **QA status:** NB (PS-ASM-12)
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
- **QA status:** NB (PS-QBK-09)
- **Review:** Draft. Expected result set by MISS-ASM-02.
