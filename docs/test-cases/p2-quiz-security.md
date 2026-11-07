# P2 test cases: assessment settings (password, randomize, specific students, additional security)

7 cases in the browser (`tests/e2e/quiz-security.spec.js` TC-QSEC-01 to 04 and `tests/e2e/specific-students.spec.js`
TC-QSEC-05 to 07), and the PHP and JavaScript tests behind the rest (see
[../assessment-settings.md](../assessment-settings.md)). Read [README.md](README.md) first. All are `Draft`.

Run them with one worker (`--workers=1`): they change the same demo assessments. TC-QSEC-04 and 07 put the data
back, so run them even after a failure. Data: IT101-A, instructor1; student5 is not chosen, student1 is.

| ID | Case | Role |
|---|---|---|
| TC-QSEC-01 | The settings of a quiz offer randomize, a password and additional security; an assignment offers none of them | Instructor |
| TC-QSEC-02 | A password is created in a modal, shown as ******, kept after saving, and never in the page | Instructor |
| TC-QSEC-03 | A student is asked for the password before the quiz starts, and a wrong one starts nothing | Student |
| TC-QSEC-04 | Put the quiz back as it was | Instructor |
| TC-QSEC-05 | Specific students: the switch shows how many are chosen, the list picks them, and a save with nobody chosen is refused | Instructor |
| TC-QSEC-06 | A student who is not chosen does not see the assignment; the chosen one does | Student |
| TC-QSEC-07 | Put the assignment back for everyone | Instructor |

### TC-QSEC-01 Quiz-only settings

- **Steps:** open Quiz 2 in the editor, then Assignment 1.
- **Expected:** the quiz shows Randomize questions, Require a password and a collapsed Additional security
  section that, opened, says a page cannot see which tab or site and cannot prevent leaving. The assignment shows
  none of them.

### TC-QSEC-02 Create the password

- **Steps:** switch the password on, Create password, Cancel, Create again, replace the suggested password with
  your own, Save; edit icon; turn on randomize and record leaving the page; Publish; reopen.
- **Expected:** the modal has a plain text field pre-filled with 10 characters; Cancel changes nothing; after Save
  the panel shows ****** and an edit icon; reopened, the settings are as left, and neither the password nor a hash
  is in the page.

### TC-QSEC-03 Student password prompt

- **Steps:** as a student, open the quiz and press Take Quiz; type a wrong password, then the right one with spaces
  around it.
- **Expected:** "This quiz needs a password" with no attempt started; "That password is not right." for the wrong
  one; the right one starts the attempt.

### TC-QSEC-05 Specific students

- **Steps:** open Assignment 1, switch Specific students on, Publish; open the list, Select all, clear it, tick
  student1, Save; Publish; reopen.
- **Expected:** "0 students selected" at first; Publish is refused with "Choose at least one student"; the list shows the
  section's students (name and email) and Select all follows the boxes; after Save "1 student selected"; reopened it is
  still on with one chosen.

### TC-QSEC-06 What a student sees

- **Steps:** as student5, then as student1, open IT101-A > Assessments.
- **Expected:** student5 has no Assignment 1 card; student1 has it.

### Already automated (no browser)

Randomized order kept on resume, password hash and lockout, security events (limits, own attempt only, quiz only),
the guard script (leaving the page counted once, blocked actions, fullscreen), the report on the grading page, and
who is not chosen (visibility, submitting, calendar, grading list, parent alerts, final grade).
