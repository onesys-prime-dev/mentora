# Institutional administrator area

The institutional administrator runs one institution inside Mentora. This page covers the foundation; each later branch adds its own section.

## Scope

- The institution is **decided on the server** from the signed-in person (`InstitutionScope`, built on `PlanLimits::institutionOf`), never from an id in the address or a form.
- Every id a page is handed (department, sub-department, program, course, offering, term, person) is checked with `InstitutionScope::require()` before anything is read or written. A foreign or missing id answers **404**, not 403, so the page does not confirm it exists elsewhere.
- A person removed from the platform (soft-deleted) is not owned.

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

On the Structure page, each sub-department lists its chairpersons with **Assign chairperson** and **Remove** (`ChairAssignmentService`).

- A sub-department can have several chairpersons and a person can chair several sub-departments. Only members of the institution who hold the chairperson role (and whose account is active) can be assigned, and only into an active sub-department.
- Removing keeps the row (`revoked`); assigning again reuses it. A chairperson's reach is derived live from these rows, so it follows on their next request.
- Audit: `structure.chairperson_assigned|revoked`.
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

## Enrolment

`/institution/enrolments/offering/{id}` (permission `enrolments.manage`; read-only while the institution is read-only). Reached from the "enrolled / capacity" figure on the Offerings list.

- **Roster:** students of one offering with status (enrolled, dropped, withdrawn, completed), student number, email and since-date; search (name, number, email), status filter, sort, 25 per page, and a places bar that says "over capacity by N" when it is.
- **Add students:** paste student numbers or email addresses, one per line or separated by commas (up to 500). They must be active people of the institution holding the student role. Anyone who cannot be enrolled is skipped and named with the reason ("is already enrolled", "has an inactive account", "does not hold the student role", "is managed by your student information system"); the rest go in together. If nobody can be enrolled the dialog says why, beside the field. A student with a dropped or withdrawn place is reinstated on the same row.
- **Capacity:** a guide with a deliberate override. Going over it is refused with "over capacity by N" until the box "Enrol even if the section goes over capacity" is ticked; the same applies to bringing someone back. Nothing is partly added.
- **Status:** change one place at a time. Dropping or withdrawing never deletes anything: the student's work and grades stay and the place can be reinstated.
- **Only an active offering** takes changes; an archived or completed one shows its roster with a closed note.
- **SIS:** while the connection is enabled, the place of a student the SIS knows in a course the SIS knows is the SIS's (`SisOwnership::managesEnrolment`): skipped on add, refused on status change, and marked "Managed by the SIS" on the roster. Students the SIS does not know can still be enrolled by hand.
- **Seats** count student accounts, not places, so enrolling an existing student never uses one.
- **Audit:** `enrolment.added|status_changed` (target type `enrollment`).

## People

`/institution/people` (permission `people.view` to look and export, `people.manage` to change; read-only while the institution is read-only). The Apps tile "People".

- **List:** people of the institution (never system administrators, never another institution), searchable by name or email, filterable by role and status, sortable, 25 per page, with a CSV export of the current filters or only the ticked rows. A person who also belongs to another institution is marked, and the other institution is never named.
- **Invitations tab:** invitations still waiting or expired. Send again (the earlier link stops working) or cancel. The account is made when the person chooses a password. A student invitation takes a seat and is refused when none is left.
- **Edit details:** name and email. Locked while the SIS manages the person.
- **Roles:** student, instructor, chairperson, parent, institutional administrator; at least one. Never system administrator.
- **Deactivate / reactivate / sign out everywhere / password link:** one at a time or (deactivate, reactivate) in bulk, reporting "8 done, 2 skipped" with the reasons. Reactivating a student needs a free seat.
- **The password is asked for** (the same dialog as elsewhere) before changing roles, deactivating (single or bulk) and sending a password link.
- **Protected:**
  - The last active institutional administrator cannot be deactivated, demoted or removed, and nobody takes the administrator role from themselves.
  - Nobody deactivates their own account.
  - A person who also belongs to another institution has an account that is not this administrator's: only "Remove from my institution" is offered. Someone who belongs only here is deactivated instead.
- **Chairperson assignments** end when the role is taken away, the person is deactivated or removed (history kept).
- **Audit:** `user.updated|deactivated|reactivated|signed_out|roles_changed|password_reset_sent|membership_removed`, `invitation.created|resent|revoked`, `users.exported`.
- **Not yet:** deans (their role arrives with the dean handout) and parent-student links (a later branch).

## Parents

`/institution/people/parents` (the third tab of People; `people.view` to look, `people.manage` to change; read-only while the institution is read-only).

- **Table:** `parent_student_links` (institution, parent, student, who made it, when). One row per pair per institution, removed with the institution. A link is made or removed, never edited.
- **Page:** the institution's accounts with the parent role, with their linked students as chips, searchable, 25 per page.
- **Link students:** paste student numbers or email addresses (one per line or comma-separated). They must be students of the institution; anyone who cannot be linked is skipped and named with the reason, the rest are linked together. The same student can have several parents.
- **Unlink:** from the chip, with a confirm that says nothing else about either person changes.
- **Audit:** `parent.linked|unlinked` (target type `parent_link`).
- **What a linked parent sees** is not decided yet; for now the link is the record that a later parent area will rely on.

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
