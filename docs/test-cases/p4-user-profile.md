# P4 test cases: My profile

26 cases: TC-PRF-01 to 05 added with Phase 2 of the User Profile rebuild, TC-PRF-06 to 09
with Phase 3 (2026-11-24), TC-PRF-10 to 12 with Phase 4 (2026-11-25), TC-PRF-13 to 26 with Phase 8 (the workflow journeys). Read [README.md](README.md) first. All are `Draft` (see the README).

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
| TC-PRF-10 | An instructor uploads a photo and a student sees it on their card | Instructor | P1 | None |
| TC-PRF-11 | Turning profile photos off shows initials and the photo address refuses; turning them on brings it back | Institution admin | P1 | None |
| TC-PRF-12 | An administrator removes someone's photo and they are told | Institution admin | P2 | None |
| TC-PRF-13 | W1 Hover an avatar, open the profile, and reach the same profile by its address | Student | P1 | None |
| TC-PRF-14 | W2 An instructor finishes their profile from the checklist | Instructor | P2 | None |
| TC-PRF-15 | A student asks to change their email and an administrator approves it | Student, then Institution admin | P1 | None |
| TC-PRF-16 | The link sent to the new address changes the sign-in email | Student | P1 | None |
| TC-PRF-17 | W5 A student changes their password and the old one stops working | Student | P1 | None |
| TC-PRF-18 | W6 A student turns two-step sign-in on and off with their password | Student | P2 | None |
| TC-PRF-19 | W7 Signing out of other devices ends the other browser's session | Student | P2 | None |
| TC-PRF-20 | W8 A parent's per-child email choice is saved | Parent | P2 | None |
| TC-PRF-21 | W9 An administrator clears someone's bio and they are told | Institution admin | P2 | None |
| TC-PRF-22 | W10 An administrator sets an instructor's home department and it shows on their profile | Institution admin | P2 | None |
| TC-PRF-23 | W12 A parent sees their child's profile without contact details, and an unlinked student is not found | Parent | P1 | None |
| TC-PRF-24 | W13 A deactivated person shows their basics and a Deactivated badge | Instructor | P2 | None |
| TC-PRF-25 | The profile, settings and person screens pass the accessibility check | Instructor | P2 | None |
| TC-PRF-26 | No horizontal overflow on /profile, /settings and the profile modal at 360, 768 and 1440 px | Student | P2 | None |

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

### TC-PRF-10 An instructor uploads a photo and a student sees it on their card

- **Role:** Instructor, then Student. **Priority:** P1
- **Data:** `instructor1`, `student1`; a generated 300 x 200 PNG
- **Steps:** as instructor1 open My profile, choose Change photo, pick the image, Save. Then, as student1, open that instructor's profile card.
- **Expected:** the photo shows in the header band and the header avatar; the student's card shows the picture, and its address answers 200.
- **Playwright:** `tests/e2e/profile-photo.spec.js` (untagged; the three cases run in order and leave photos on and no photo)
- **QA status:** None
- **Review:** Draft

### TC-PRF-11 Turning profile photos off shows initials and the photo address refuses; turning them on brings it back

- **Role:** Institution admin. **Priority:** P1
- **Data:** `institution.admin`, `instructor1` with the photo from TC-PRF-10
- **Steps:** in Institution settings switch Profile photos off and save; open instructor1's My profile and the photo address; switch it on again.
- **Expected:** with photos off the band and header show initials and the photo address answers 404; with them on the same photo shows again.
- **Playwright:** `tests/e2e/profile-photo.spec.js` (untagged)
- **QA status:** None
- **Review:** Draft

### TC-PRF-12 An administrator removes someone's photo and they are told

- **Role:** Institution admin, then Instructor. **Priority:** P2
- **Data:** `institution.admin`, `instructor1` with a photo
- **Steps:** open instructor1's person page, Remove photo, confirm. Then sign in as instructor1 and open the bell.
- **Expected:** the person page shows initials; instructor1's profile shows initials and the bell lists "Your photo was removed by your school".
- **Playwright:** `tests/e2e/profile-photo.spec.js` (untagged)
- **QA status:** None
- **Review:** Draft

### TC-PRF-13 W1 Hover an avatar, open the profile, and reach the same profile by its address

- **Role:** Student. **Priority:** P1
- **Data:** `student1`, IT101-A announcements
- **Steps:** hover the author's avatar, choose See more, then open `/people/{id}`.
- **Expected:** the card and the modal name the author; the page shows the same profile; axe finds no serious problem.
- **Playwright:** `tests/e2e/profile-journeys.spec.js` (untagged)
- **QA status:** None
- **Review:** Draft

### TC-PRF-14 W2 An instructor finishes their profile from the checklist

- **Role:** Instructor. **Priority:** P2
- **Data:** `instructor1`, empty bio
- **Steps:** on My profile choose the bio checklist item, write text, Save.
- **Expected:** the bio shows on the page and the item leaves the checklist; axe passes.
- **Playwright:** `tests/e2e/profile-journeys.spec.js` (untagged)
- **QA status:** None
- **Review:** Draft

### TC-PRF-15 A student asks to change their email and an administrator approves it

- **Role:** Student, then Institution admin. **Priority:** P1
- **Data:** `student5`, `institution.admin`
- **Steps:** request an email correction with a reason; as the admin open People, Requests, Review, Approve change.
- **Expected:** the student sees Waiting for your school; the admin sees the request (axe passes) and the list empties after approval.
- **Playwright:** `tests/e2e/profile-journeys.spec.js` (untagged)
- **QA status:** None
- **Review:** Draft

### TC-PRF-16 The link sent to the new address changes the sign-in email

- **Role:** Student. **Priority:** P1
- **Data:** `student5`, the mail sink
- **Steps:** open the emailed link, Confirm new email, then sign in with the new address.
- **Expected:** the page says the email changed and the new address signs in (state restored afterwards).
- **Playwright:** `tests/e2e/profile-journeys.spec.js` (untagged)
- **QA status:** None
- **Review:** Draft

### TC-PRF-17 W5 A student changes their password and the old one stops working

- **Role:** Student. **Priority:** P1
- **Data:** `student3`
- **Steps:** Settings, Password: current, new, confirm, Change password; sign in elsewhere with the old password.
- **Expected:** the change is confirmed and the old password is refused; the demo password is restored.
- **Playwright:** `tests/e2e/profile-journeys.spec.js` (untagged)
- **QA status:** None
- **Review:** Draft

### TC-PRF-18 W6 A student turns two-step sign-in on and off with their password

- **Role:** Student. **Priority:** P2
- **Data:** `student4`
- **Steps:** Settings, Two-step sign-in: turn on; turn off and type the password.
- **Expected:** the switch shows on, then off.
- **Playwright:** `tests/e2e/profile-journeys.spec.js` (untagged)
- **QA status:** None
- **Review:** Draft

### TC-PRF-19 W7 Signing out of other devices ends the other browser's session

- **Role:** Student. **Priority:** P2
- **Data:** `student2` in two browser contexts
- **Steps:** Settings, Sign out of all other devices, confirm.
- **Expected:** the other context is sent to Login; this one stays signed in.
- **Playwright:** `tests/e2e/profile-journeys.spec.js` (untagged)
- **QA status:** None
- **Review:** Draft

### TC-PRF-20 W8 A parent's per-child email choice is saved

- **Role:** Parent. **Priority:** P2
- **Data:** `parent1`
- **Steps:** Settings, Notifications: flip the first child's email switch, Save.
- **Expected:** the choice persists after the reload; it is put back afterwards.
- **Playwright:** `tests/e2e/profile-journeys.spec.js` (untagged)
- **QA status:** None
- **Review:** Draft

### TC-PRF-21 W9 An administrator clears someone's bio and they are told

- **Role:** Institution admin. **Priority:** P2
- **Data:** `institution.admin`, `instructor2` with a bio
- **Steps:** open the person page, Clear a field, Clear bio, confirm.
- **Expected:** the About card disappears and the person has a notification; data restored.
- **Playwright:** `tests/e2e/profile-journeys.spec.js` (untagged)
- **QA status:** None
- **Review:** Draft

### TC-PRF-22 W10 An administrator sets an instructor's home department and it shows on their profile

- **Role:** Institution admin. **Priority:** P2
- **Data:** `institution.admin`, `instructor2`
- **Steps:** People, Actions, Edit details, choose a Home department, Save; open the person page.
- **Expected:** the department shows in the profile band; restored afterwards.
- **Playwright:** `tests/e2e/profile-journeys.spec.js` (untagged)
- **QA status:** None
- **Review:** Draft

### TC-PRF-23 W12 A parent sees their child's profile without contact details, and an unlinked student is not found

- **Role:** Parent. **Priority:** P1
- **Data:** `parent1` (follows student1 and student5), `student2`
- **Steps:** open My profile, then `/people/{child}` and `/people/{student2}`.
- **Expected:** the child's page answers 200 with no Contact card or email; the unlinked student answers 404.
- **Playwright:** `tests/e2e/profile-journeys.spec.js` (untagged)
- **QA status:** None
- **Review:** Draft

### TC-PRF-24 W13 A deactivated person shows their basics and a Deactivated badge

- **Role:** Instructor. **Priority:** P2
- **Data:** `instructor1`, `student1` deactivated for the case
- **Steps:** open `/people/{student1}`.
- **Expected:** the Deactivated badge shows and there is no Contact card; the account is reactivated afterwards.
- **Playwright:** `tests/e2e/profile-journeys.spec.js` (untagged)
- **QA status:** None
- **Review:** Draft

### TC-PRF-25 The profile, settings and person screens pass the accessibility check

- **Role:** Instructor. **Priority:** P2
- **Data:** `instructor1`
- **Steps:** open /profile, /settings and a person page and scan with axe (wcag2a, wcag2aa).
- **Expected:** no serious or critical violation.
- **Playwright:** `tests/e2e/profile-journeys.spec.js` (untagged)
- **QA status:** None
- **Review:** Draft

### TC-PRF-26 No horizontal overflow on /profile, /settings and the profile modal at 360, 768 and 1440 px

- **Role:** Student. **Priority:** P2
- **Data:** `student1`
- **Steps:** at each width open /profile, /settings and a profile modal from an announcement avatar.
- **Expected:** `scrollWidth` never exceeds the window width.
- **Playwright:** `tests/e2e/profile-journeys.spec.js` (untagged)
- **QA status:** None
- **Review:** Draft
