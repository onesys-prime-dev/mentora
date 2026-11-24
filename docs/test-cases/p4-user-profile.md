# P4 test cases: My profile

5 cases, added with Phase 2 of the User Profile rebuild (2026-11-24). Read
[README.md](README.md) first. All are `Draft` (see the README).

**Data:** `instructor1@mentora.test` and `student5@mentora.test`. The edit case
changes instructor1's preferred name and pronouns and puts them back.

| ID | Case | Role | Priority | QA status |
|---|---|---|---|---|
| TC-PRF-01 | An instructor opens My Profile from the account menu | Instructor | P1 | None |
| TC-PRF-02 | An instructor edits their preferred name and pronouns and sees them in the header band | Instructor | P1 | None |
| TC-PRF-03 | A student's profile shows Studies and Official details with the lock | Student | P1 | None |
| TC-PRF-04 | "As a student sees it" hides Official details and the edit pencils | Student | P2 | None |
| TC-PRF-05 | Help & Support in the account menu is disabled | Instructor | P3 | None |

### TC-PRF-01 An instructor opens My Profile from the account menu

- **Role:** Instructor. **Priority:** P1
- **Data:** `instructor1`
- **Steps:** sign in; open the account menu; choose My Profile.
- **Expected:** the address is `/profile`; the header band shows the instructor's name and the Instructor role; the About and Office & consultation cards are shown.
- **Playwright:** `tests/e2e/profile.spec.js` (untagged)
- **QA status:** None
- **Review:** Draft

### TC-PRF-02 An instructor edits their preferred name and pronouns and sees them in the header band

- **Role:** Instructor. **Priority:** P1
- **Data:** `instructor1`
- **Steps:** open `/profile`; press "Edit name & pronouns"; enter a preferred first name, choose they/them; save.
- **Expected:** the dialog closes and the band shows the preferred name and `they/them`. (The test then clears both.)
- **Playwright:** `tests/e2e/profile.spec.js` (untagged)
- **QA status:** None
- **Review:** Draft

### TC-PRF-03 A student's profile shows Studies and Official details with the lock

- **Role:** Student. **Priority:** P1
- **Data:** `student5`
- **Steps:** sign in; open `/profile`.
- **Expected:** the Studies card is shown (Contact is for viewers, not the owner); the Official details card is shown with "Managed by your school" and has no edit button.
- **Playwright:** `tests/e2e/profile.spec.js` (untagged)
- **QA status:** None
- **Review:** Draft

### TC-PRF-04 "As a student sees it" hides Official details and the edit pencils

- **Role:** Student. **Priority:** P2
- **Data:** `student5`
- **Steps:** open `/profile`; choose "As a student sees it".
- **Expected:** the address ends `?preview=student`; no Official details card; no edit pencil or "Edit name & pronouns" button; choosing "Me" brings them back.
- **Playwright:** `tests/e2e/profile.spec.js` (untagged)
- **QA status:** None
- **Review:** Draft

### TC-PRF-05 Help & Support in the account menu is disabled

- **Role:** Instructor. **Priority:** P3
- **Data:** `instructor1`
- **Steps:** open the account menu.
- **Expected:** Help & Support is shown as "Coming soon", is not a link and is marked disabled.
- **Playwright:** `tests/e2e/profile.spec.js` (untagged)
- **QA status:** None
- **Review:** Draft
