# Audit decisions — 2026-10-23

The decisions made for each finding in [audit-2026-10-23.md](audit-2026-10-23.md), numbered by finding. They say what the app should do; they are not an implementation plan.

Context that shaped them: Mentora is still in development, and no real institution uses it yet. One-time safety steps for live data were therefore dropped (3.3, 3.4, 6.5, 7.8), and existing conflicts are fixed automatically (9.6, 16.0d).

## Finding 1: grade-override requests stuck when a department has no dean

- **1.1** When a department has no dean, the institution admin decides.
- **1.2** When a dean is assigned, requests waiting for the admin move to the new dean.
- **1.3** The admin's options are the dean's: approve, or reject with a reason. There is no send-back.
- **1.4** All institution admins are notified, any one of them can decide, and the first decision wins.
- **1.5** No one decides their own request, admins included. This also covers a dean's own request, which always goes to the admin.
- **1.6** In a one-admin institution, the admin's own request waits, and the requester is told it needs a second admin.

## Finding 2: departments with no dean never send grades to the SIS

- **2.1** In a department with no dean, the institution admin signs off.
- **2.2** An institution with no deans at all needs no sign-off; finalized grades go straight to the SIS.
- **2.3** Whether a sign-off is needed is checked when grades are sent. When the first dean is assigned, unsent grades then need one, and admins are told which sections are waiting.
- **2.4** The admin's sign-off works exactly like the dean's: one section or a whole program, reopen with a reason, and a password check.
- **2.5** Sign-offs already given stay valid when the person who signs changes. A new dean can still reopen one, with a reason.

## Finding 3: view-as uses up a student's quiz attempt

- **3.1** Opening a quiz while viewing as a student shows the landing information: availability, attempts used, past results. If the student has an attempt in progress, it is shown read-only. No attempt is started and no unseen questions are shown.
- **3.2** One absolute rule for both kinds of view-as (system admin and institution admin): nothing is ever saved on the viewed person's behalf, even by opening a page.
- **3.3 and 3.4** dropped: there is no live data, so no past attempts need reviewing.

## Finding 4: CSRF was never switched on

- **4.1** Every request that changes data needs a valid CSRF token. The AI worker's internal API (`internal/ai/*`) is the only exception.
- **4.2** When a token has expired, the person sees a plain "This page was open too long. Reload it and try again." message, and what they typed is kept where possible.
- **4.3** Sign-out works only through a button press; a plain link can't sign anyone out.
- **4.4** A test proves CSRF is on: a change without a token is refused, and the test fails if the protection is turned off.

## Finding 5: dean and institution-admin lesson links crash

- **5.1** The direct link opens the course page on that lesson, read-only, as it does for an instructor.
- **5.2** Read-only viewers see draft lessons, clearly marked as drafts.
- **5.3** A deleted lesson, a lesson from another course, and a course outside the viewer's reach all show the same friendly "not found" page.

## Finding 6: scheduled jobs are not installed

Who gets the missed-job alert, and what sends it: see U2.

- **6.1** Subscription access follows the dates. An institution becomes read-only and then past on the dates the rules give, whether or not the nightly job ran.
- **6.2** When a scheduled job misses its window, the system admin sees a warning on the admin home page and gets an email, until the job runs again.
- **6.3** A deploy fails if the scheduled jobs aren't installed, on staging and production.
- **6.4** All six jobs are installed on every server, whether or not it uses SIS.
- **6.5** dropped: the purge job runs normally from its first run.

## Finding 7: Roles-page permissions that change nothing

"Roles page" in this finding now means the platform defaults, which each institution can override for five roles (U1).

- **7.1** Every permission on the Roles page must take effect. Each one is either made to work or removed from the page.
- **7.2** `grades.approve` and `grades.signoff` are kept and enforced. A dean without one counts as "no dean" for that duty.
- **7.3** The institution admin also needs `grades.approve` and `grades.signoff` to stand in, and both are added to the admin's defaults. If no role holds a permission, the request or sign-off waits, the requester is told why, and a warning shows on the Roles page and on the institution admin's permissions page (U1f).
- **7.4** `courses.manage`, `grading.manage` and `announcements.manage` are enforced. Clearing one makes that part of the course read-only for that role.
- **7.5** `program.monitor` controls the chairperson's Monitor Program page, and is removed from the institution admin's defaults.
- **7.6** `followups.manage` controls creating and passing down follow-ups, and chairpersons get it by default. Replying to and closing a follow-up you received needs no permission.
- **7.7** `audit.manage` controls the audit-log settings screen, and `monitoring.manage` is removed because there is nothing for it to control.
- **7.8** dropped: enforcement starts with whatever is stored.

## Finding 8: two memberships override read-only

- **8.1** Whether a change is allowed depends on the institution it belongs to; being writable elsewhere doesn't count.
- **8.2** In a read-only institution, people can still change their own account settings: password, notification read state, a parent's email choices, personal reminders. Institution records stay frozen.
- **8.3** The same applies to past and suspended institutions.

## Finding 9: the instructor group skips the quiz rules

- **9.1** What someone may do in a course depends only on their role in that course.
- **9.2** No one can be both a student and an instructor in one section, whichever role comes first.
- **9.3** That extends to the whole course, even different sections, because the sections share a question bank.
- **9.4** A chairperson counts as an instructor of every course in their program. They can't be enrolled in one, and can't become chair while enrolled in one.
- **9.5** Deans (for their department's courses) and institution admins (for their institution's courses) also can't be enrolled as students in those courses.
- **9.6** Existing conflicts are fixed automatically.
- **9.7** When fixing a conflict, the enrolment is removed and the staff role stays.

## Finding 10: purge keeps uploaded files

- **10.1** Purge deletes every file used in the institution, both the database row and the file on disk.
- **10.2** A file another institution still uses is kept.
- **10.3** Accounts that belong to no other institution are deleted, with their profile, notifications, reminders and remaining files.
- **10.4** The institution's data-export zips are deleted.
- **10.5** The institution's audit log entries are kept, for accountability. Backups age out on their own after 14 newer backups.

## Finding 11: a broken backup is recorded as good

- **11.1** A backup counts as successful only once the whole file was written and reads back to the end. Otherwise it counts as failed, the file is deleted, and the system admin is warned and emailed.
- **11.2** Only proven-good backups count toward the 14 kept, so a failing run never causes a good backup to be deleted.
- **11.3** The read-back check is enough for now; a test restore can wait until real institutions arrive.
- **11.4** An off-server backup copy is required before the first real institution goes live. What, where, how often and who: see U3.

## Finding 12: the data export can run out of memory

- **12.1** The export must handle 20,000 students, 5 years of terms and about 50 graded items per course, which is up to a few million submission rows.
- **12.2** It is prepared in the background. The admin can leave the page and is notified, in the app and by email, when it's ready.
- **12.3** Only one export is prepared at a time per institution. A second click shows that one is already being prepared.
- **12.4** Only the admin who asked is notified when it's ready or fails. Other admins see it on the export page.

## Finding 13: class schedules can't be entered

- **13.1** The institution admin enters a section's class times on the offering form.
- **13.2** Where the SIS sends class times, they come from the SIS and are locked in Mentora. Otherwise the admin enters them.
- **13.3** Weekly pattern only (day, start, end, room, delivery mode); no one-off exceptions. Cancellations go out as announcements.
- **13.4** A room or instructor clash shows a warning, but the save is still allowed.
- **13.5** Class times follow the platform's time zone (U4).

## Finding 14: the AI worker test depends on the local `.env`

- **14.1** The PHP test suite must pass whatever a developer's `.env` contains; a test that needs a setting sets it itself. This also covers the four other files that set settings the same way (`MysqldumpRunnerTest.php`, `InstitutionCsvUploadTest.php`, `AiGenerateModalViewTest.php`, `FakeBackupRunner.php`).
- **14.2** CI also runs the suite with a fully filled-in sample `.env`.

## Finding 15: opening a quiz in two tabs at once

- **15.1** A second tab opened at the same moment resumes the same attempt: same timer, same saved answers.
- **15.2** The last save for each question wins. Once one tab submits, the other can't change anything.

## Finding 16: chairperson actions are not atomic

- **16.0** Exactly one chairperson per sub-department. This changes today's documented rule (`ChairAssignmentService.php:11-12`).
- **16.0b** A new chair replaces the old one after the admin confirms. The old chair is notified, and their assignment ends with its history kept.
- **16.0c** Requests waiting for the old chair's endorsement pass to the new chair, who is notified.
- **16.0d** Where a sub-department already has several chairs, the most recently assigned is kept and the others' assignments end, automatically.
- **16.1** When the chair and the requester act on the same request at the same moment, only the first action counts. The second person is told, and the request ends in one clear state with one notification.

## Settled after review

These were the open questions left after the first pass.

### U1: who controls the permissions (from 2.2 and 7.2)

- **U1a** For their own institution, the institution admin controls the permissions of five roles: dean, chairperson, instructor, student and parent. `institutional_admin` stays with the system admin, and the admin role stays locked.
- **U1b** The system admin sets the platform defaults. An institution overrides only the boxes it changes. Boxes it hasn't touched follow the default, including later changes to the default.
- **U1c** The same safeguards as the system admin's page: a password re-check, an entry in the institution's audit log, and every role keeps at least one permission.
- **U1d** Only academic permissions are offered. Platform and institution-administration permissions never are.
- **U1e** If an institution takes `grades.approve` or `grades.signoff` away from its deans, the institution admin takes over. The review step is never removed. This counts as "no dean" (2.1), not "no deans anywhere" (2.2).
- **U1f** The "no one can approve or sign off" warning shows on both the institution admin's permissions page and the system admin's Roles page.

### U2: the missed-job alert (6.2)

- All system admins get it.
- The hourly uptime check (`docs/uptime.md`) sends it by checking that the scheduled jobs have reported recently. The alert can't come from the cron that has stopped, because `NotificationTriggers` only runs from `subscriptions:sync` (`SyncSubscriptions.php:62`).

### U3: the off-server backup copy (11.4)

- **U3a** The copy holds the production database dumps and the uploaded files (`writable/uploads`). Staging is left out. Today the uploads aren't backed up at all (`docs/backup.md:58-59`).
- **U3b** It goes to a different provider than Hostinger, under a separate account. It's encrypted before it leaves the server, and the server can add copies but can't delete or overwrite them.
- **U3c** It runs after every backup that passes the 11.1 check, and copies are kept for 30 days. A copy counts as good once the storage confirms it holds the whole file. A failed copy warns and emails the system admins.
- **U3d** The repo owner sets it up, as a required step in `docs/server-setup.md`. It counts as done once one copy has been fetched back and opened, both the dump and a sample of the files. No real institution goes on production before that.

### U4: class time zone (13)

- Class times follow the platform's time zone (`Platform.php:55-56`), like every other time in the app.
- Per-institution time zones wait until an institution outside that zone signs up, and would then cover every time in the app.

### U5: one chair instead of "every chair" (16.0 and 1.4)

- The one chair is notified (`GradeOverrideRequestService.php:111, 171`) and is the only one who can endorse.
- If the sub-department has no chair, or the chair made the request, the chair step is skipped and the request goes to the dean, or to the admin (1.1). The requester sees that the step was skipped.
- A chair assigned later picks up only requests still waiting for a chair (16.0c). Requests already past that step don't move back.

## Added during the design (2026-10-24)

Settled while designing the fixes ([design](superpowers/specs/2026-10-23-audit-fixes-design.md)). Where one changes an earlier decision, it says so.

- **D1** One account belongs to exactly one institution; the system admin belongs to none. A parent with children at two schools uses two accounts. The multi-institution cases of 8.1, 10.2 and 10.3 can no longer happen.
- **D2** Each role is offered only the permissions that do something for it, on both permission pages.
- **D3** A dean may sign off a section they teach.
- **D4** An institution admin may not sign off a section they teach; another institution admin must. With one admin, it waits and they are told it needs a second admin.
- **D5** The deploy installs the scheduled jobs itself, then checks they are there, and fails only if that check fails. **Replaces 6.3.**
- **D6** New permission `discussions.manage` (pin, lock, delete others' posts, mark the answer), on by default for instructors and chairpersons.
- **D7** Student and staff in the same course are refused within the same term, and no one may ever enrol in a course they have taught or chaired. A former student may teach it later. Refines 9.3.
- **D8** A sixth SIS dataset for class times, per offering. Extends 13.2.
- **D9** The class-times file is the whole timetable: a section in it gets exactly its rows and is locked; a SIS-owned section left out loses its SIS times; a section the SIS never sent is untouched; no file changes nothing.
- **D10** A former student may later chair that program; a former dean or institution admin may never enrol in a course they led; someone whose enrolment ended in a past term may later be made staff.
- **D11** The missed-job email goes out once when a job goes quiet and once when it recovers; the admin home warning stays in between.
- **Also:** the background export adds a seventh scheduled job (6.4). Auditing a course (an ungraded enrolment) is out of scope.
