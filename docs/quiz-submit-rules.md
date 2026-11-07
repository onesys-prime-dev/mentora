# Quiz submit rules and the leave-page warning

For a student taking a quiz (`quiz-answer-form.php`, `quiz-taking/`).

## Submit

| Situation | What happens |
|---|---|
| A required question is unanswered | The Submit button is disabled, with "Answer the N required questions to submit." The server refuses too: `submitAttempt` answers `success: false`, `unanswered_required: N` and leaves the attempt in progress. |
| Only optional questions are unanswered | Submit opens a dialog: "You have 3 unanswered questions. Submit anyway?" |
| Everything answered | Submit opens a dialog once: "You won't be able to change them afterward." |
| After a successful hand-in | A toast: "Quiz submitted." (or "Time is up. Your quiz was submitted." when the timer did it). |
| The server refuses or the network fails | A toast with the reason; the quiz and its warning stay on. |

- "Answered" is the same on the page and the server: a choice is picked, or text other than spaces is typed.
- A File Upload question cannot be answered inside a quiz yet, so it is never required.
- The rule follows the questions the attempt **started with** (the attempt keeps a copy), not later edits.
- **Timer ended:** the browser hands the quiz in the moment its timer reaches zero, whatever is blank. The server
  allows that for up to 5 seconds of clock difference (`AUTO_SUBMIT_GRACE_SECONDS`); a hand-in before that is held to the rule.
  Saving an answer keeps its own 60 seconds of allowance.

## Leaving the page while the quiz is open

| How they leave | What they see |
|---|---|
| Refresh, close the tab, type another address | The browser's own warning. A page cannot choose the words of that one. |
| A link, a course tab or the back arrow in the page; the browser's Back button | Our dialog "Leave this quiz?" with Stay and Leave anyway. |

- Timed quiz: "Your answers are saved, but the timer keeps running while you're away. Leave anyway?"
- Untimed quiz: "Your answers are saved. Leave this page?"
- Nothing is asked once the quiz is handed in (the guard stops before the page moves on).
- Links that open a new tab, download, or stay on the page are not asked about.
- Browser Back: the page puts the quiz's address back, asks, and goes back for real on Leave anyway.

## Code and tests

`quiz-taking/submit-rules.js`, `leave-guard.js`, `confirm.js` (the dialog), wired in `quiz-taking.js`;
`QuizAttemptService::unansweredRequired`. Tests: `tests/database/QuizRequiredQuestionsTest.php`,
`tests/js/pages/quiz-submit-rules.test.js`, `quiz-leave-guard.test.js`, `quiz-confirm.test.js`, and
`tests/e2e/quizzes.spec.js` (TC-QUIZ-01).
