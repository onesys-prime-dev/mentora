# P4 test cases: My profile

9 cases: TC-PRF-01 to 05 added with Phase 2 of the User Profile rebuild, TC-PRF-06 to 09
with Phase 3 (2026-11-24). Read [README.md](README.md) first. All are `Draft` (see the README).

**Data:** `instructor1@mentora.test` and `student5@mentora.test`. The edit case
changes instructor1's preferred name and pronouns and puts them back. The avatar
cases use `student1@mentora.test` on IT101-A's Announcements, which has
instructor1's demo announcements.

| ID | Case | Role | Priority | QA status |
|---|---|---|---|---|
| TC-PRF-01 | An instructor opens My Profile from the account menu | Instructor | P1 | None |
| TC-PRF-02 | An instructor edits their preferred name and pronouns and sees them in the header band | Instructor | P1 | None |
| TC-PRF-03 | A student's profile shows Studies and Official details with the lock | Student | P1 | None |
| TC-PRF-04 | "As a student sees it" hides Official details and the edit pencils | Student | P2 | None |
| TC-PRF-05 | Help & Support in the account menu is disabled | Instructor | P3 | None |
| TC-PRF-06 | Hovering an author's avatar shows their profile card | Student | P1 | None |
| TC-PRF-07 | Clicking an author's avatar opens their profile | Student | P1 | None |
| TC-PRF-08 | The avatar works from the keyboard | Student | P1 | None |
| TC-PRF-09 | On a phone a tap opens the profile | Student | P2 | None |

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

### TC-PRF-06 Hovering an author's avatar shows their profile card

- **Role:** Student. **Priority:** P1
- **Data:** `student1`, IT101-A
- **Steps:** sign in; open IT101-A; open Announcements; rest the pointer on the author's avatar of the first announcement.
- **Expected:** after a moment a small card appears by the avatar with the author's name; the card and the announcement have no WCAG 2 A/AA violations (axe).
- **Playwright:** `tests/e2e/profile-card.spec.js` (untagged)
- **QA status:** None
- **Review:** Draft

### TC-PRF-07 Clicking an author's avatar opens their profile

- **Role:** Student. **Priority:** P1
- **Data:** `student1`, IT101-A
- **Steps:** on IT101-A's Announcements, click the author's avatar.
- **Expected:** the Profile dialog opens with the author's profile and name; it has no WCAG 2 A/AA violations (axe).
- **Playwright:** `tests/e2e/profile-card.spec.js` (untagged)
- **QA status:** None
- **Review:** Draft

### TC-PRF-08 The avatar works from the keyboard

- **Role:** Student. **Priority:** P1
- **Data:** `student1`, IT101-A
- **Steps:** on IT101-A's Announcements, press Tab until the author's avatar has focus; press Enter; press Escape.
- **Expected:** with focus on the avatar the card appears; Enter opens the Profile dialog; Escape closes it and focus is back on the avatar.
- **Playwright:** `tests/e2e/profile-card.spec.js` (untagged)
- **QA status:** None
- **Review:** Draft

### TC-PRF-09 On a phone a tap opens the profile

- **Role:** Student. **Priority:** P2
- **Data:** `student1`, IT101-A, a 360 x 800 touch screen
- **Steps:** on IT101-A's Announcements, tap the author's avatar.
- **Expected:** the Profile dialog opens with the author's name; no hover card is shown.
- **Playwright:** `tests/e2e/profile-card.spec.js` (untagged)
- **QA status:** None
- **Review:** Draft
