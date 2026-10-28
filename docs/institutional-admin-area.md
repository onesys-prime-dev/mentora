# Institutional administrator area

The institutional administrator runs one institution inside Mentora. This page covers the foundation; each later branch adds its own section.

How its screens are built (tokens, components, patterns and the acceptance list) is in [ui-guide.md](ui-guide.md).

## Scope

- The institution is **decided on the server** from the signed-in person (`InstitutionScope`, built on `PlanLimits::institutionOf`), never from an id in the address or a form.
- Every id a page is handed (department, sub-department, program, course, offering, term, person) is checked with `InstitutionScope::require()` before anything is read or written. A foreign or missing id answers **404**, not 403, so the page does not confirm it exists elsewhere.
- A person removed from the platform (soft-deleted) is not owned.
- **Read-only** follows the institution's effective status (`EffectiveStatus`): the subscription dates count even before the nightly `subscriptions:sync` has moved the stored status. A person's own things stay open in a read-only or past institution: their calendar reminders, and a parent's email choices and password link (`ReadOnlyFilter::OWN_ACCOUNT`).

## Routes

| Path | Does | Read-only institution |
| --- | --- | --- |
| `/institution`, `/institution/dashboard` | Home: snapshot, plan and usage | opens |
| `/institution/export` (+ download) | Data export | works (deliberately outside the `readonly` filter) |

Everything that **writes** goes in the nested `readonly` group in `app/Config/Routes.php`. `InstitutionHomeTest` fails if a write route is added outside it.

## Permissions

Added to `Config\AuthGroups`, granted to `institutional_admin` by default, editable by the system administrator in the roles matrix: `structure.manage`, `terms.manage`, `offerings.manage`, `enrolments.manage`, `people.view`, `people.manage`, `reports.view`, `institution.settings`, `institution.audit`, `institution.impersonate`, `sis.run`.

## Shell

- Same app shell as the student and instructor areas (`layouts/app`, the shared components in `app/Views/components`).
- The Apps menu lists the institution's tools (`global_apps('institutional_admin')`); tiles without a page yet are inert and say "Coming soon".
- `role_home()` maps a role to its area (`institutional_admin` -> `institution`) for the header's logo link.

## Home

Snapshot (people by role, courses, this term's offerings and enrolments) from `InstitutionSnapshot`, and plan usage from `PlanLimits::report()`.

## Confirming the password

Dangerous actions sit behind the `reauth` route filter, the same one the system administrator uses.

- It lets a request through only while the person typed their password within the last 5 minutes (`ReauthService::WINDOW_SECONDS`).
- Otherwise a normal request is sent to the confirm page (`/reauth`; the system administrator's stays `/admin/reauth`) and, once confirmed, returns to the page it came from. The return address can only be inside an administrative area (`admin/`, `institution/`), never another site.
- A script (the on-page dialog) gets `403 {"error":"reauth_required"}` instead. `resources/js/shared/dialog.js` then opens the page's confirm dialog (`layouts/partials/reauth-dialog.php`, opt in with `'reauthDialog' => true`), posts the password as JSON, and repeats the original action with what was typed still in place. A closed dialog repeats nothing and says nothing was saved.
- Five wrong passwords in a row end the session.

To guard a route: `['filter' => ['role:institutional_admin', 'permission:...', 'reauth']]`.

## Academic structure

`/institution/structure` (permission `structure.manage`; the page is read-only while the institution is read-only).

- **Levels:** departments -> sub-departments -> programs. Created, renamed, switched off and back on; nothing is deleted.
- **Codes:** capital letters, numbers, dots, dashes, underscores (up to 50). Unique where the database says: departments within the institution, sub-departments within their department, programs within their sub-department.
- **Switching off:** a node cannot be switched off while it has active children (sub-departments, programs, courses) or offerings in an active term; the message says which. Nothing is created under an inactive parent, and nothing is reactivated under one. Programs can also be archived.
- **Departments** gained an `is_active` flag for this (existing ones stay active).
- **Audit:** `structure.<level>_created|updated|deactivated|reactivated` (programs also `archived`), with old and new values.
- **Forms:** short forms are one shared dialog per level, filled from the row's button (`data-dialog-*` attributes, see `resources/js/shared/dialog.js`). The server answers a dialog form with JSON (`422 {errors}` shown beside the field, or `{message, redirect}`) and a plain post with a redirect and a message, so it also works without JavaScript.

## Demo account

`institution.admin@mentora.test` / `Institution123!` (institutional administrator of institution 1), from `DemoUsersSeeder`.

## Courses

`/institution/courses` (permission `structure.manage`; read-only while the institution is read-only). The Structure and Courses pages are tabs of the same "Academics" area.

- **Catalog:** every course of the institution with search (code or title), program and status filters, sortable columns, 25 per page. Filters live in the address.
- **Rules:** code unique within its program (capitals, numbers, spaces, dots, dashes, underscores); title 3 to 255 characters; units above 0 and at most 99.9; a course is filed under an *active* program and is never deleted (inactive, archived). It cannot be switched off while it has offerings in an active term, and not reactivated under an inactive program.
- **SIS lock:** while the connection is enabled, a course the SIS supplied cannot have its code, title or units changed by hand (`SisOwnership::managesCourse`). The edit dialog shows them read-only with a note; the description stays editable. The server refuses it too (422 with the field).
- **Audit:** `structure.course_created|updated|deactivated|archived|reactivated`.

## Chairpersons

On the Structure page, each sub-department shows its chairperson with **Assign chairperson** (when it has none), **Replace chairperson** and **Remove** (`ChairAssignmentService`).

- **One chairperson per sub-department** (audit 16.0); a person can chair several sub-departments. The database keeps it so: `active_marker` is 1 on the one active row and a unique key covers `(sub_department_id, active_marker)`. Only members of the institution who hold the chairperson role (and whose account is active) can be assigned, and only into an active sub-department.
- **Replacing:** assigning someone to a sub-department that has a chairperson is refused ("… chairs …. Confirm to replace them.") unless the administrator confirms (`replace=1`, the Replace chairperson dialog). The old assignment then ends (`revoked`, history kept) and the old chair is told; override requests waiting for a chairperson's endorsement pass to the new chair, who is told. A request the new chair made themselves moves on to the dean (or the administrators), since nobody else could endorse it. If another administrator changed the chair at the same moment, the second is told to reload.
- **The SIS:** a chairperson the SIS supplies is replaced or removed there, not by hand (no Replace button; a request anyway gets the SIS message). The SIS sync replaces the chair it lists, and a file listing two chairpersons for one sub-department fails the second row.
- **Existing data:** the migration `OneChairpersonPerSubDepartment` keeps each sub-department's most recently assigned chair and ends the others.
- Removing keeps the row (`revoked`); assigning again reuses it. Requests that were waiting for the removed chair move on to the dean (or the administrators) instead of waiting for nobody (`grade.override_rerouted`). A chairperson's reach is derived live from these rows, so it follows on their next request.
- Audit: `structure.chairperson_assigned|revoked` (a replacement records both, the revoked one with `replaced_by`).
- Giving someone the chairperson role is done on the People page (a later branch).

## Terms

`/institution/terms` (permission `terms.manage`; read-only while the institution is read-only). Academics tab next to Structure and Courses.

- **Fields:** name (2 to 100 characters), academic year (`2026-2027`, the second year following the first, or just `2026`), start and end dates. Name and year together are unique within the institution.
- **Dates:** real dates, the end after the start, and no longer than about two years (a typo guard).
- **Overlaps are allowed, with a warning.** Schools do run overlapping terms: the page marks them with an "Overlaps" badge and the save message says which term. Starting a term while another is active warns the same way.
- **Status here is upcoming <-> active.** An active term with students enrolled cannot go back to upcoming. **Completing a term is the "close a term" step** (a later branch), which also completes its offerings and enrolments; a completed term cannot be edited or reopened from here.
- **Audit:** `structure.term_created|updated|started|set_upcoming`.
- **Warnings:** a change that goes through but deserves a second look hands back warnings; `AnswersDialogForms` adds them to the message and the JSON (`warnings`).

## Offerings

`/institution/offerings` (permission `offerings.manage`; read-only while the institution is read-only). Fourth tab of Academics, and an Apps tile.

- **An offering** is an active course taught in an upcoming or active term, as a section. Course, term and section together are unique; the section is shown in capitals. Capacity is 1 to 1000; the description is optional (5,000 characters).
- **List:** search (course code, title, section), filters (term, program, instructor, status), sort (course, section, term, capacity, enrolled, status), 25 per page. Filters live in the address. Instructor "No instructor" finds offerings nobody teaches; they are flagged in the list.
- **Edit:** section, capacity, description, and only while the offering is active. Capacity cannot go below the students already enrolled.
- **Archive, never delete.** An offering with enrolled students cannot be archived (the message gives the count). Reactivating needs an upcoming or active term.
- **Instructors:** chosen from active people of the institution who hold the instructor role. Assigning and removing works on active offerings only; removing keeps past grades and the audit trail.
- **Audit:** `structure.offering_created|updated|archived|reactivated|instructor_assigned|instructor_unassigned`.

### Class times

`/institution/offerings/{id}/schedule`, the "Class times" link on each row of the Offerings list (same permission, read-only institution and 404 for another institution's section as above). The list shows each section's week in a few words ("Mon 09:00–10:30 · Rm 204", or "—").

- **A week of meetings:** day, start, end, room (optional, up to 50 characters) and delivery (onsite, online, hybrid), in the platform's time zone. The page shows the saved rows plus three blank ones; leaving a row empty removes it, and saving replaces the whole week (`ClassScheduleService`). No one-off exceptions: a cancelled class is an announcement.
- **Refused, with the row:** no day, a time that is not `HH:MM`, the end not after the start, an unknown delivery, a room over 50 characters, or the same day and start twice ("Row 2: the section already meets on Monday at 09:00."). What was typed stays on the form.
- **Clashes are warnings, never a refusal.** After a save, the page lists every other section of the same term that meets at an overlapping time on the same day in the same room (ignoring case; not for an online meeting or an empty room) or with an instructor in common: "Room Rm 204 is also used by IT101 B on Monday 09:30–11:00." / "Ivy Tan also teaches IT102 A on Tuesday 13:00–14:30."
- **Only an active section** takes changes; an archived or completed one shows its times read-only.
- **From the SIS:** a section whose times the SIS sends (`class_schedules.csv`, see `sis-integration.md`) shows them read-only, with a "From the SIS" badge and "These class times come from your student information system. Change them there; the next sync brings them here." The server refuses a save as well. Disabling the connection hands the times back.
- **Audit:** `structure.offering_schedule_changed` with the week before and after.

## Enrolment

`/institution/enrolments/offering/{id}` (permission `enrolments.manage`; read-only while the institution is read-only). Reached from the "enrolled / capacity" figure on the Offerings list.

- **Roster:** students of one offering with status (enrolled, dropped, withdrawn, completed), student number, email and since-date; search (name, number, email), status filter, sort, 25 per page, and a places bar that says "over capacity by N" when it is.
- **Add students:** paste student numbers or email addresses, one per line or separated by commas (up to 500). They must be active people of the institution holding the student role. Anyone who cannot be enrolled is skipped and named with the reason ("is already enrolled", "has an inactive account", "does not hold the student role", "is managed by your student information system", or a course-role conflict such as "teaches or has taught this course", see `course-access.md`); the rest go in together. If nobody can be enrolled the dialog says why, beside the field. A student with a dropped or withdrawn place is reinstated on the same row.
- **Capacity:** a guide with a deliberate override. Going over it is refused with "over capacity by N" until the box "Enrol even if the section goes over capacity" is ticked; the same applies to bringing someone back. Nothing is partly added.
- **Status:** change one place at a time. Dropping or withdrawing never deletes anything: the student's work and grades stay and the place can be reinstated.
- **Only an active offering** takes changes; an archived or completed one shows its roster with a closed note.
- **SIS:** while the connection is enabled, the place of a student the SIS knows in a course the SIS knows is the SIS's (`SisOwnership::managesEnrolment`): skipped on add, refused on status change, and marked "Managed by the SIS" on the roster. Students the SIS does not know can still be enrolled by hand.
- **Seats** count student accounts, not places, so enrolling an existing student never uses one.
- **Audit:** `enrolment.added|status_changed` (target type `enrollment`).

## Roles and permissions

`/institution/roles` (permission `institution.roles`; a change also needs the password; read-only while the institution is read-only). The Apps tile "Roles and permissions".

- **What:** the academic permissions of the institution's dean, chairperson, instructor, student and parent roles, on top of the platform's defaults. The same grid as the system administrator's page; "Edited" marks a box that differs from the platform's, and a box never changed follows the platform, later changes included.
- **Not here:** the institutional administrator and system administrator roles, and any platform or institution-administration permission.
- **Rules:** a role keeps at least one permission; a permission a role is not offered cannot be set.
- **Audit:** `roles.permission_changed` with the institution. More in `roles-and-permissions.md`.
- **Warnings:** where nobody can approve grade overrides or sign off grades in a department ("Nobody can … in …"), the page says so; the system administrator's Roles page lists the same for every institution (`GradeAuthority::gaps`).

## Who decides grade overrides and sign-off

`GradeAuthority` answers it for the override chain, sign-off and the SIS grade export.

- **Whether approval is used:** an institution uses approval and sign-off once it has any active dean assignment. With none, overrides stay direct and finalized grades go straight to the SIS, as before. When the first dean is assigned, the finalized sections whose grades were not sent yet are announced to whoever now signs them off: the new dean for their department, the administrators for the rest.
- **Who:** in a department, its dean if they hold the permission as dean (`grades.approve`, `grades.signoff`); otherwise the institution's active administrators who hold it. Both permissions are institutional administrator defaults.
- **Nobody decides their own request**, and an administrator never signs off a section they teach (another administrator does). A dean may sign off a section they teach.
- **Changing the permission** (either Roles page) moves waiting requests to whoever now decides them.

## Approvals

`/institution/approvals` (permission `grades.approve`; read-only while the institution is read-only). The dean's Approvals page, for the requests waiting for the institution (`awaiting_institution`).

- **Pending:** the requests of the institution waiting for an administrator, with the same filters as the dean's. **Decided:** those decided by the institution's administrators.
- **Deciding:** approve or reject, never your own. The first administrator to decide wins; the next is told "That request was just decided or withdrawn." Every administrator who may decide it is told when one arrives (link to this page).
- **When it can't be decided:** the requester is told why it waits ("This needs another administrator…" or "Nobody in your institution can decide this yet…").

## Sign-off

`/institution/signoff` (permission `grades.signoff`; signing off a section or a program also needs the password). The dean's Sign-off board, for the programs of departments with no dean who signs off.

- **Board:** term filter, programs with their ready sections; signing off one section or a whole program, and reopening.
- **Own sections:** a section the administrator teaches is marked and can't be signed off by them ("You teach this section, so another administrator signs it off."). "Sign off all N ready" leaves them out of N and says how many were left for another administrator.
- **Notices:** when a section is ready, the administrators who may sign it off are told, minus the section's instructors.
- **Not here:** the dean's CSV export and follow-ups.

## People

`/institution/people` (permission `people.view` to look and export, `people.manage` to change; read-only while the institution is read-only). The Apps tile "People".

- **List:** people of the institution (never system administrators, never another institution), searchable by name or email, filterable by role and status, sortable, 25 per page, with a CSV export of the current filters or only the ticked rows.
- **Invitations tab:** invitations still waiting or expired. Send again (the earlier link stops working) or cancel. The account is made when the person chooses a password. A student invitation takes a seat and is refused when none is left.
- **Edit details:** name and email. Locked while the SIS manages the person.
- **Roles:** student, instructor, chairperson, parent, institutional administrator; at least one. Never system administrator.
- **Deactivate / reactivate / sign out everywhere / password link:** one at a time or (deactivate, reactivate) in bulk, reporting "8 done, 2 skipped" with the reasons. Reactivating a student needs a free seat.
- **The password is asked for** (the same dialog as elsewhere) before changing roles, deactivating (single or bulk) and sending a password link.
- **Protected:**
  - The last active institutional administrator cannot be deactivated, demoted or removed, and nobody takes the administrator role from themselves.
  - Nobody deactivates their own account.
  - An account belongs to one institution (decision D1), so a person is never removed from it: they are deactivated instead.
- **Chairperson assignments** end when the role is taken away or the person is deactivated (history kept).
- **Audit:** `user.updated|deactivated|reactivated|signed_out|roles_changed|password_reset_sent`, `invitation.created|resent|revoked`, `users.exported`.
- **Not yet:** deans (their role arrives with the dean handout) and parent-student links (a later branch).

## Parents

`/institution/people/parents` (the third tab of People; `people.view` to look, `people.manage` to change; read-only while the institution is read-only).

- **Table:** `parent_student_links` (institution, parent, student, who made it, when). One row per pair per institution, removed with the institution. A link is made or removed, never edited.
- **Page:** the institution's accounts with the parent role, with their linked students as chips, searchable, 25 per page.
- **Link students:** paste student numbers or email addresses (one per line or comma-separated). They must be students of the institution; anyone who cannot be linked is skipped and named with the reason, the rest are linked together. The same student can have several parents.
- **Unlink:** from the chip, with a confirm that says nothing else about either person changes.
- **Invite a parent with their students:** the Invite dialog on People has an optional **Students to link** box (student numbers or emails, one per line), for the Parent role only. A parent has no account until they accept, so the list is checked when the invitation is sent and kept on it; the links are made in the same step that makes the account, with the administrator who sent it as the actor.
  - **Strict at invite:** every name must be a student of this institution. One that is not (a typo, another institution, not a student) stops the invitation: nothing is sent and each unmatched name is shown with its reason on the field.
  - **At acceptance** a student who has since left or been deleted is skipped (the account is still made, and `invitation.accepted` records how many were linked).
  - **Sending again** keeps the students; a fresh invitation to the same address replaces the open one and carries the list typed for it. The pending list shows "n students to link".
  - Naming students for any other role is refused.
- **Audit:** `parent.linked|unlinked` (target type `parent_link`); a link made through an invitation also records `via: invitation` and the invitation id.
- **What a linked parent sees:** see "Parent portal" below and `docs/course-access.md`.

## Parent portal switch

Institution settings has a **Parents** tab (`institution/settings/parents`, `institution.settings`; read-only while the institution is read-only). One On/Off choice for the whole institution.

- The portal is on only when Mentora's `parent.portal` feature flag is on for the institution **and** the institution has not switched it off. The administrator can only switch it off (and back on): when Mentora has it off, the tab says so and the portal stays off whatever is chosen.
- Off means every parent of the institution sees a "switched off" page and no child data is read. Nothing is deleted, and links are kept.
- The choice is stored per institution (`parentPortalEnabled`) and audited like any setting (`settings.changed`). A switch that cannot be read counts as off.

## Uploading from CSV

`/institution/people/upload` (People page, "Upload CSV"; `people.manage`; applying also needs the password; read-only while the institution is read-only). A three-step page: Upload, Preview, Apply.

- **It is the SIS import with a different source.** `UploadConnections` stands in for a SIS connection and points `SisImporter` at the uploaded files, so the importer runs unchanged and gives the same guarantees: a preview that writes nothing and reports exactly what applying does, one transaction (all or nothing), and a bad row refused with its reason while the rest still go in. Same columns as the SIS files (`docs/sis-integration.md`); a header-only template of each file can be downloaded.
- **Upload:** any of students, instructors, courses and enrolments (at least one). A file that cannot be used as a whole (missing column, empty, over 10 MB) is refused and nothing is kept.
- **Preview:** counts to create, to update, unchanged and refused (as a whole and per file), the refused rows with their reason, and "Nothing has been saved yet". The files are kept for an hour under a random token inside the institution's own folder (`writable/csv-uploads/<institution>/<token>`, or `upload.csvPath`); another institution's token is a 404.
- **Apply:** behind the password, uses the kept files, removes them, and says how many were created, updated and refused. If the run fails part-way nothing is changed and the files are kept for another try. Starting over removes them.
- **Refused while a SIS connection is enabled:** one source of truth at a time (a connection that is configured but switched off does not block it).
- **Records linked by an upload** are not SIS-owned and stay editable while no connection is enabled; if one is enabled later, matching external ids become SIS-owned as usual.
- **History:** preview and apply appear in the run history like any import (trigger "manual"); applying is audited as `sis.import_run`.

## Closing a term

`/institution/terms/{id}/close` (permission `terms.manage`; the password; read-only while the institution is read-only). "Close term" on an upcoming or active term; "Reopen" on one closed here.

- **The confirm page** says exactly what will change before anything does: how many offerings and enrolments become completed, how many are left as they are (archived offerings), and a table of the offerings with their enrolled students.
- **Closing** (one transaction): the term, its active offerings and the places still `enrolled` in them become `completed`. Archived offerings and places that were dropped, withdrawn or completed are left alone. Nothing is deleted. Audited as `structure.term_closed` with the counts.
- **What "closed" means for people:** an offering that is not active is read-only for instructors and students. Writes under `.../api/courses/{offering}/...` (and a calendar announcement naming one) are refused with 403 `offering_closed` by `ClosedOfferingFilter`; reads, downloads and exports go through. Their course page carries a "This course is closed" note.
- **Past courses:** completed offerings are no longer in the current course list but appear under "Past courses" on the Courses page (student, instructor, chairperson). A completed place still counts as part of the class everywhere a roster, gradebook, grade sheet, progress or file access looks (`enrolled` or `completed`). Archived offerings stay unreachable.
- **Reopening** puts back exactly what the close changed: the term keeps who closed it, when, and the ids of the offerings and places it completed (`close_snapshot`). A place or offering changed since is left as it is. A term that became completed any other way has no snapshot and cannot be reopened here. Audited as `structure.term_reopened`.
- **Not yet:** dean sign-off before closing (the dean handout), and refusing a reopen once offerings were copied from the term (offering copy, the next branch).

## Copying offerings into another term

`/institution/terms/copy` (permission `terms.manage`; read-only while the institution is read-only). "Copy offerings" on the Terms page, and on a closed term's row. A page with steps: choose the terms, choose the offerings, copy.

- **Terms:** copy from any of the institution's terms into a different upcoming or active one.
- **Choose:** the source term's offerings (archived ones are not offered) with what each holds (modules, items, assessments, instructors) and whether the target already has that section. All are ticked except clashes; "Also assign the same instructors" is optional.
- **Copied** (a new active offering in the target, same course and section): capacity and description, weekly schedule, modules, units, items, their prerequisites and resources, assessments with settings, questions and choices, rubrics and criteria, and the grading scheme with its periods, categories and gradebook items (flagged for recalculation). Item and assessment statuses are kept as they are.
- **Not copied:** enrolments, submissions and answers, scores and grades, announcements, discussions, progress, reminders, AI generation history.
- **Dates:** assessment dates move by the difference between the two terms' start dates (said on the page).
- **Files** are referenced, not duplicated: the copy's resources point at the same stored file, so no extra storage is used. Deleting a resource from one side keeps the file while the other still uses it.
- **All or nothing:** a run is one transaction. A section that already exists in the target is a clash and refuses the whole run until it is unticked. If anything fails part-way nothing is changed.
- **Reopening:** each copy remembers its source (`course_offerings.copied_from_offering_id`). A term that offerings were copied from stays closed ("Copied into another term, so it stays closed").
- **Audit:** `structure.offerings_copied` with the counts, the source term, the date shift and whether instructors were copied.

## Brand colour tokens

The three brand colours are tokens, not hex values, so one place decides them (and, with the branding branch, one institution can override them).

| Token | Default | Utilities |
| --- | --- | --- |
| `--color-brand` | `#0040A6` | `bg-brand`, `text-brand`, `border-brand`, `ring-brand/50`, `accent-brand` ... |
| `--color-brand-dark` | `#002068` | `bg-brand-dark`, `text-brand-dark`, `from-brand-dark/90` ... |
| `--color-brand-tint` | `#F2F7FF` | `bg-brand-tint`, `hover:bg-brand-tint` ... |

- **Defined** in `resources/css/app.css` (`@theme`). Utilities read them at runtime, so setting the property on `:root` or on any element restyles everything inside it.
- **Rule:** views, helpers and scripts use the utilities, never `[#0040A6]`, `[#002068]` or `[#F2F7FF]`. (Other greys, status tones and the icon colours are unchanged.)
- **JavaScript:** `resources/js/shared/brand-tokens.js` reads (`readBrandTokens`), sets (`applyBrandTokens`, six-digit hex only) and clears (`clearBrandTokens`) them, for a live preview or a chart that needs the value.
- **No visual change** from the switch: the defaults are the same colours as before.

## Institution settings: profile and branding

`/institution/settings` (permission `institution.settings`; read-only while the institution is read-only). The Apps tile "Settings". Two tabs for now: Profile and Branding.

- **Profile:** display name (2 to 150 characters), contact email, contact phone (digits, spaces and + - ( ) only) and address, all optional but the name. The code is shown and cannot be changed. Audited as `institution.profile_updated`.
- **Colours:** a primary and an accent colour, typed as hex or picked. They are refused, beside the field, when they are not readable: the primary must reach 4.5:1 (WCAG AA) against the pale tint derived from it (white text on it, and it as text on white, both follow), the accent against white. The message gives the contrast it would have. The page says so as the colour is typed, and a live preview takes it at once. Choosing the Mentora colours stores nothing, so the institution follows the platform if it changes. "Reset to the Mentora colours" clears both. Audited as `institution.branding_updated`.
- **Applied** by custom properties on `:root` in the head of every page the institution's people see (`--color-brand`, `--color-brand-dark` and `--color-brand-tint` derived from the primary, `--color-accent`), so every brand utility changes with them. Only validated colours are ever written. System administrators belong to no institution, so the vendor's area keeps the platform colours; someone of another institution is unaffected.
- **Accent:** the `--color-accent` token and `bg-accent`/`text-accent` utilities exist and the preview uses them, but no screen uses the accent colour yet.
- **Logo:** PNG, JPEG or WebP, up to 1 MB and between 32 and 2000 pixels a side (SVG is refused: it can carry script). It is a file of the institution, so it counts towards its storage and is refused when the plan has no room. Replacing or removing it frees the old file. It is shown in the header beside Mentora's, and is served to the institution's people only (`FileAccessService`). Audited as `institution.logo_changed`.
- **Columns:** `institutions.contact_email`, `contact_phone`, `address`, `logo_file_id`, `brand_primary`, `brand_accent` (all null until set).

## Academic defaults

`/institution/settings/academic` (the third tab of Institution settings; `institution.settings`; read-only while the institution is read-only).

- **What new offerings start with:** the passing mark, the grade scale and the default grading categories (names and weights that add up to 100%). An offering's grading scheme is made the first time it is needed, from these; **offerings that already have a scheme keep it**.
- **What counts as needing attention:** days without activity (default 14; a student or a course is silent), "behind" below a percentage of the published items (50), and days of ungraded work before it is overdue (7). They replace the constants the at-risk list, the Monitor Program, the inactive-courses widget and the grading queue used, so the chairperson, the instructor and the reports read the same numbers. The grading queue's amber "soon" colour comes at about half of the overdue days.
- **Resolution:** for each value, the institution's own setting, else the platform's (the system default grading scheme for the passing mark, scale and categories; the built-in values above for the thresholds). Each field says which it is using. An institution that sets nothing behaves exactly as before.
- **Stored in:** `institution_academic_defaults` (one row per institution, every column optional) and, for the categories, a scheme row with `grading_schemes.institution_id` set (no offering, not the system default, so the platform template is never mistaken for it).
- **Refusals** are shown beside the field: a passing mark or percentage outside 1 to 100, days outside their range, a scale that does not exist, a category with no name or a repeated one, and weights that do not add up (the message says what they add up to). Nothing is saved when anything is wrong.
- **Reset** ("Go back to the platform values") removes everything the institution set.
- **Audit:** `institution.academic_defaults_updated` with the values before and after.

## Announcement and security

`/institution/settings/announcement` and `/institution/settings/security` (the last two tabs of Institution settings; `institution.settings`; read-only while the institution is read-only).

### Announcement
- One line (plain text, up to 300 characters, no `<` or `>`) shown at the top of every page for the institution's own people, **under the platform's announcement**. Optionally "show until" a date (the whole of that day, in the platform's timezone). People can hide it for themselves; it comes back when its text changes.
- Not shown to other institutions or to system administrators. Saving it empty (or "Remove announcement") takes it down. Stored per institution in the settings store, so each change is audited like any setting.

### Security (stricter only)
| Setting | Stricter means | Where it is enforced |
| --- | --- | --- |
| Shortest password | longer | `PasswordPolicy`, on accepting an invitation (the inviting institution's rule) and on a password reset |
| Sign out after inactivity | fewer minutes | `AccountStateFilter` |
| Failed sign-ins before a lockout | fewer (any number beats "never"; 0 cannot loosen) | `LoginLockout`, by the institution of the account the email belongs to |
| Lockout length | longer | `LoginLockout` |
| Roles that need an emailed code | more roles (the platform's stay on) | `AdminEmail2FA` |

- **In force** is the stricter of the platform's and the institution's value, decided in one place (`InstitutionSecurity`). Someone who belongs to no institution, and every system administrator, is held to the platform's.
- **A looser value is refused** beside its field, naming what the platform already requires; nothing is saved when anything is refused. A value equal to the platform's is not stored, so the institution follows the platform if it tightens. If the platform later asks for more than the institution did, the platform's value is the one in force.
- **The password is asked for** (the same dialog as elsewhere) before saving or resetting, because tightening can lock people out. Changes are audited as `settings.changed` / `settings.reset` with the institution.

## Monitor Program over a set of programs

`MonitorProgramService` no longer assumes one chairperson. Its metrics already took plain lists of offering ids; what tied it to a chairperson was how the offerings were found and the audit viewer. Both now take a scope.

- **`offeringRowsForPrograms(programIds, filters)`** returns every offering in a set of programs (any status, optionally one term) in the shared row shape (`App\Models\Courses\OfferingDetail`, also what the chairperson's Courses page reads). An empty set is no offerings, never all of them.
- **`chairedProgramIds(user)`** is the chairperson's scope: the programs under the sub-departments they actively chair, read live (a program added later is covered at once, an ended assignment stops at once). `offeringRows(user, filters)` is now exactly `offeringRowsForPrograms(chairedProgramIds(user), filters)`, so the chairperson's page is unchanged.
- **`termsForPrograms(programIds)`** feeds the term filter for any scope.
- **`auditEntries(actor|null, offeringRows, filters)`** is the audit viewer for a scope: with an actor it is that person's own chairperson actions (the chairperson page, via `auditLog()`), with none it is every chairperson action on the offerings in scope.
- **Every metric** (snapshot, enrolment and completion, grades, instructor activity, at risk, the comparison table) is the same code for one program or many, so a department's or an institution's numbers are the union of the single programs' and always agree with the chairperson's. The thresholds they use are the institution's academic defaults.
- **Used next by** the institution reports (and, later, the dean's Monitor Department).

## Reports

`/institution/reports` (permission `reports.view`; reading and exporting still work in a read-only institution). The Apps tile "Reports".

- **It is Monitor Program over the institution.** Nothing is calculated again: `InstitutionReports` takes the programs in scope and hands them to `MonitorProgramService`, so the institutional administrator, the chairperson and the dashboards read the same numbers, with the same thresholds (the institution's academic defaults).
- **Filters** (in the address, so a view can be shared): term, department, program, and an activity date range. Choosing a department narrows the program list. A department or program that is not the institution's answers 404; a term that is not its own matches nothing.
- **The page:** totals (courses, instructors, students, who needs attention, how much is graded and how fast), the grade distribution, a sortable comparison of courses (enrolment, completion, average grade, grading time) and the students who need attention (the 25 silent longest; the export has everyone).
- **A course:** each course links to its own page: enrolment, completion, grades and distribution, grading time, instructor activity and its students who need attention. (The read-only view of its content is a later branch.)
- **CSV, one per report:** course comparison, at-risk students, grades and grading, instructor activity. Each is for the same filters as the page, with a name or title that could be read as a spreadsheet formula defused, and each export is itself audited as `institution.report_exported` with the filters and the row count.
- **Empty:** an institution with nothing to report says so instead of showing zeros.

## Read-only course view

Any offering of the institution can be opened and looked at, as its instructor sees it, by the institutional administrator (permission `courses.view`). "View course" on the Offerings list, and "Open the course (view only)" on a course's report page.

- **Addresses:** `institution/courses/{offering}/{slug}/{feature}` for the page and `institution/api/courses/{offering}/...` for what it loads. They are the same controllers and views as the instructor's and chairperson's, not copies; the workspace takes its address prefix from the page (`courseUrlPrefix`).
- **A view marker** ("View only. You are looking at IT101 A as an administrator of this institution...") sits above the tabs. A completed or archived course carries its own closed note as well.
- **What is shown:** overview, announcements, modules (drafts included, like the instructor's), resources, discussions, assessments (with their questions and rubrics), and the gradebook (scheme, roster, and the offline grade sheet download). The grading screens, the editors and the question tools are not offered.
- **Nothing can be changed.** Only reading routes exist under these addresses: there is no route to post, patch or delete anything in a course, and a test walks every write route of the course area and checks none of them exists here. The instructor-only controls are not drawn either, because the page does not treat the viewer as an instructor.
- **Scope** is the institution's own: an offering of another institution is a 404 on the page and on every API address (`InstitutionCourseFilter`), and the files a course shares (its resources and announcement attachments) open only for its own institution's administrator. A student's submitted work never does.
- **One rule in one place:** `CourseViewer` says who sees the instructor's view; the services that used to ask "is this the instructor?" for what to show now ask it, and `InstitutionCourseAccess` decides reach. Writes keep their own checks (the assigned instructor, the program's chairperson).
- **Read-only institutions** can still be looked at; viewing was never a write.
- **A lesson's direct link** (`.../modules/items/{item}`, for the institution, the dean, the chairperson, the instructor and the student) opens the course page on that lesson: it answers with a redirect to `.../modules?item={item}`. A draft lesson opens for those who see the instructor's view, marked as a draft; a student sees only a published lesson. A deleted lesson, one from another course, and a course out of reach all give the same "not found" page.

## When an institution is purged

The system administrator's purge (by hand, or `institutions:purge-expired` once the retention period is over) removes the institution and everything under it, in one transaction:

- **Its accounts go too:** every member who belongs to no other institution, except system administrators. Their profiles, roles, sign-ins and work go with them through the database's cascades.
- **Its files go, row and disk:** everything those accounts uploaded, the institution's logo, and the export zips made for it. The files on disk are deleted after the database work is committed; a file that cannot be deleted then is remembered (`Purge.pendingFiles`) and tried again by the next scheduled purge.
- **The audit entry** (`institution.purged`) records how many accounts and files went.

## Subscription

`/institution/subscription` (permission `institution.settings`) shows the plan, its period, where it is on the timeline (running, expiring, grace, read-only, expired), usage against the plan's limits (seats, storage, AI generations) and the renewal history. It has no forms: plans and renewals belong to the system administrator. History rows never show who recorded them.

## Integrations

`/institution/integrations` (permission `sis.run`) shows the SIS connection's state and the run history, and lets the administrator preview or run an import and preview or send final grades. It calls the same services as the system administrator's page, so every rule holds (feature flag, enabled connection, read-only institution). A real import and a grade delivery ask for the password.

What is never shown: the connection's settings, credentials, folder names (taken out of run messages and row issues) and connection tests.

## Audit log

`/institution/audit` (permission `institution.audit`) lists what happened in the institution, newest first: when, who, the role they acted as, what, what it was about, and the before and after values of a change. Filters are a search, a date range, who (part of an email), what and what about; the columns sort; 25 per page. `Export CSV` downloads the same filtered list (itself recorded as `institution.audit_exported`) and stays open while the institution is read-only.

What it holds and what it never shows:
- Only the institution's own entries. The search is bound to the institution on the server, so a filter, a search, a choice list or an export cannot name another one.
- No source addresses: never selected, searched or exported, whatever the vendor's own display setting says.
- What the vendor's staff did about the institution is listed under the name "Mentora support", not their accounts; a view-as by them shows the same name.
- Before and after values of changes to the SIS connection's settings and of backups are kept by the vendor ("Kept by Mentora"), as the integrations page keeps folders and credentials out.
- Cells that start with `=`, `+`, `-` or `@` are defused in the CSV.

## Viewing as one of the institution's people

On the People page, `Actions` has `View as this person` (permission `institution.impersonate`). It asks for a reason (5 to 500 characters, kept in the audit log) and the administrator's password, then shows the site as that person sees it.

- **View only.** Every request that would change something is refused on the server, whatever the page or a script sends; reading works.
- **Nothing is saved for the person, even by opening a page** (audit 3.2): no quiz attempt is started, and their "last active" time is not stamped. Opening a quiz shows its landing instead: "You are viewing as {name}. Opening the quiz here does not start an attempt.", whether it is open, the attempts used of the limit, and the past results; an attempt already running is shown read-only (its saved answers and minutes left, no saving or submitting). `ViewAsWritesNothingTest` opens every page of each role while viewing and checks no table changed but the audit log (and the course gradebook set up on first opening, which is nobody's). A banner on every page names the person, the minutes left and the reason, with `Exit view`. Signing out also just ends the view.
- **Time limited** by the platform's `impersonationMinutes` setting. When it passes, the next request ends the view and returns to People.
- **Who can be viewed:** a live account of this institution: student, instructor, chairperson or parent. Never an administrator of any kind, never oneself, and a person of another institution is answered as one who does not exist.
- **Works in a read-only institution**, since it changes nothing.
- **Recorded:** start and end are audit entries (reason, time watched), and every entry made in between carries the administrator as impersonator, which the audit page shows as "viewing as, by ...".

The service is the system administrator's own (`Impersonation`), extended with the institution rules; the system administrator's behaviour is unchanged.

## Home: what needs attention, recent activity, quick access

`/institution/dashboard` starts with **Needs attention**, most urgent first (red, then amber, then blue), each with a button to where it is dealt with. It says so in words when there is nothing, rather than showing an empty list. The questions are `InstitutionAttention`, with the day passed in, so the page and the notifications agree:

| Item | When |
|---|---|
| Subscription ending, in grace, read-only, ended | By the plan's timeline (not repeated under the read-only banner) |
| A limit at 90% or 100% | Seats, storage, AI generations |
| A failed SIS run | A real run (not a test or preview) failed in the last 7 days and no good run in the same direction has followed |
| No term is active | No term has the status active |
| The active term ends within 14 days | Today counts; after it ended it does not |
| Offerings with no instructor | Active offerings of the active term that nobody teaches; links to the offerings list already filtered |
| Courses with no offering this term | Active courses not offered in the active term |
| Invitations expired / about to expire | Still open, and lapsed or lapsing within 3 days |

**Recent activity** is the latest six audit entries of the institution (no source addresses), with a link to the full log; without `institution.audit` the card says so. **Quick access** offers only what this administrator may do right now: inviting, uploading and adding offerings need their permission and a writable institution; reports need `reports.view`; the data export is always there.

### Notifications for the institution's administrators

Sent by `subscriptions:sync` with the others, each once (running again tells nobody twice), in the app only unless urgent:

- A failed SIS import or grade delivery (urgent: also by email), now to the institution's administrators as well as the system administrators.
- The active term ends within 14 days.
- Offerings still have no instructor once the term has started (once per term).
- Invitations are about to expire (told again only if the set of lapsing invitations changes).

The system administrators are not told about an institution's calendar.
