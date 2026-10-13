# P2 test cases: the system administrator

12 cases, automated in `tests/e2e/system-admin.spec.js`. Read [README.md](README.md)
first. **All are `Draft`** (see the README).

The administrator signs in with a 6-digit code the app emails. In the browser
tests nothing is really sent: the app is pointed at `tests/e2e/support/mail-sink.php`
(`E2E_MAIL_SINK`, see `app/Config/Email.php`), which files each message in
`writable/e2e-mail`, where the test reads the code and the invitation link.
The administrator account is created by `tests/e2e/support/serve.js` with
`php spark admin:create` (the demo seeder refuses to run in production).

Cases TC-SA-04 to TC-SA-08 are one story about a new institution, **E2E College**,
and run in order.

| ID | Case | Role |
|---|---|---|
| TC-SA-01 | The administrator needs the emailed code, and the admin area stays closed until it is entered | System administrator |
| TC-SA-02 | Nobody but the system administrator can open the admin area | Student, Instructor, Anonymous |
| TC-SA-03 | The home page says what needs attention and its menu reaches the right pages | System administrator |
| TC-SA-04 | Onboarding an institution in three steps, and finding it afterwards | System administrator |
| TC-SA-05 | The invited administrator sets a password, signs in, and cannot export while the subscription runs | Institutional administrator |
| TC-SA-06 | A lapsed subscription makes the institution read-only, and its administrator can then export | Institutional administrator |
| TC-SA-07 | Recording a renewal brings a read-only institution back | System administrator |
| TC-SA-08 | Viewing as a student is view only, always marked, and ends with one click | System administrator |
| TC-SA-09 | What happened is in the audit log | System administrator |
| TC-SA-10 | The main administration screens pass an automated accessibility check | System administrator |
| TC-SA-11 | The users list narrows by search, and ticking people shows how many are chosen | System administrator |
| TC-SA-12 | A guarded action asks for the password in a dialog over the page, and carries on with the keyboard alone | System administrator |

## TC-SA-01 The administrator needs the emailed code

- **Steps:** sign in with the administrator's email and password. On "One more step" press *Send code*. Before entering anything, open `/admin/dashboard`. Enter a wrong code. Ask for a new code and enter the right one.
- **Expected:** the admin area stays closed after the password and after a wrong code; the right code opens `/admin/dashboard` ("Home").
- **Already automated:** `tests/feature/AdminTwoFactorTest.php`, `tests/session/`.

## TC-SA-02 Nobody but the system administrator can open the admin area

- **Steps:** as a student, then an instructor, then signed out, open `/admin/dashboard`, `/admin/institutions`, `/admin/users`, `/admin/audit-log`.
- **Expected:** signed in people are sent to their own area; signed out visitors to the login page. No admin page is shown.
- **Already automated:** `tests/feature/RouteAccessTest.php` (every admin route, every role).

## TC-SA-03 Home and menu

- **Steps:** sign in as the administrator. Look at the home page. Use *Add an institution* and each of: Institutions, Users, System status, Audit log, General.
- **Expected:** the page shows *Needs attention* and *Snapshot*, and the menu in four groups; every link reaches its own page.
- **Already automated:** `tests/feature/AdminHomePageTest.php`, `tests/database/AdminHomeTest.php`.

## TC-SA-04 Onboarding

- **Steps:** *Add an institution* with the name "E2E College" and code "E2ECOL". Choose the plan "Standard", a period starting today and ending in 30 days. Enter the first administrator (Ada Principal, ada@e2e-college.test) and *Finish onboarding*. Open the institutions list.
- **Expected:** "E2E College is onboarded", the institution is **active**, and it is in the list.
- **Already automated:** `tests/database/OnboardingServiceTest.php`, `tests/database/OnboardingRollbackTest.php`, `tests/feature/AdminOnboardingTest.php`.

## TC-SA-05 The invited administrator

- **Steps:** open the link in the invitation email. Set a password. Open the same link again. Sign in with the new password.
- **Expected:** the link works once; the administrator lands on *Data export*, which says it is available once the subscription has ended; no read-only banner; the admin area is closed to them.
- **Already automated:** `tests/database/InvitationServiceTest.php`, `tests/feature/InstitutionExportTest.php`.

## TC-SA-06 Lapse to read-only and export

- **Setup:** the subscription period ended 20 days ago (the test moves the dates, then runs `php spark subscriptions:sync`, as the scheduler does).
- **Steps:** the institution's administrator signs in. Press *Create an export*. Download it.
- **Expected:** the read-only banner is shown; the export is made and downloads as `e2ecol-….zip`.
- **Already automated:** `tests/database/SubscriptionLifecycleTest.php`, `tests/feature/ReadOnlyModeTest.php`, `tests/database/InstitutionExportServiceTest.php`.

## TC-SA-07 Renewal

- **Steps:** as the system administrator open E2E College: the status is *read-only*. Confirm the password. On the Subscription tab record a renewal ending in about a year.
- **Expected:** the institution is **active** again, and its administrator no longer sees the banner.
- **Already automated:** `tests/database/SubscriptionLifecycleTest.php`.

## TC-SA-08 Viewing as a student

- **Steps:** confirm the password. Find the demo student `student5@mentora.test` in Users. Give a reason and *View as*. Open the courses. *Exit view*.
- **Expected:** the student's dashboard opens with a banner saying "view only" on every page; *Exit view* returns to the administrator's page of that person, with no banner.
- **Already automated:** `tests/feature/ImpersonationTest.php`.

## TC-SA-09 The audit log

- **Steps:** open the audit log.
- **Expected:** it holds `impersonation.started`, `impersonation.ended`, `invitation.accepted` and `institution.exported`.
- **Already automated:** `tests/feature/AdminAuditLogPageTest.php`.

## TC-SA-10 Accessibility

- **Steps:** sign in as the administrator and open Home, Institutions, Plans, Users, Feature flags, Roles, System status, Backups, Audit log, Archive, General and Security. Run the automated check (axe, WCAG 2 A and AA) on each.
- **Expected:** no serious or critical problem. Found and fixed when the case was added: the System status page refreshed itself with a `<meta>` refresh the person could not stop; it now reloads from a script that a "Refresh every minute" box turns off (the `<meta>` stays only for browsers without scripts).
- **Already automated:** `tests/feature/AdminMonitoringPageTest.php`.

## TC-SA-11 The users list

- **Steps:** open Users. Search for `student5@mentora.test`. Tick the one row.
- **Expected:** one row remains; before ticking there is no bulk bar; after, it says "1 person selected" and offers Deactivate, Reactivate and Export selected.
- **Already automated:** `tests/feature/AdminUsersPageTest.php`, `tests/js/shared/data-table.test.js`.

## TC-SA-12 The password in a dialog

- **Steps:** on Backups, focus *Back up now* with the keyboard and press Enter. Press Escape. Press Enter again, type the password and press Enter.
- **Expected:** the first time a dialog "Confirm your password" opens over the page (the address does not change); Escape closes it, nothing is requested and focus is back on the button; the second time the backup is requested and its message and a *requested* row are on the page.
- **Already automated:** `tests/feature/AdminAjaxRedirectTest.php`, `tests/js/shared/dialog.test.js`.
