# P1 test cases: the critical journeys

17 cases. Read [README.md](README.md) first for the format, the demo accounts and
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
