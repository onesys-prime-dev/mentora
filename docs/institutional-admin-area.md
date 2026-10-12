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
