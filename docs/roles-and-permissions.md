# Roles and permissions

Who may do what. Two pages set it: the system administrator's (the platform defaults) and each institution administrator's (their own institution's changes on top).

## Two layers

- **Platform** (`/admin/roles`, `roles.view` to look, `roles.manage` and a password check to change): the default for every institution. Rows in `role_permissions` with `institution_id = 0`.
- **Institution** (`/institution/roles`, `institution.roles` to look, plus a password check to change; refused while the institution is read-only): the boxes this institution changed. Rows with `institution_id = <the institution>`.
- An institution row is stored only where it differs from the platform's current value. A box the institution never touched follows the platform, later changes included. Setting a box back to the platform's value removes the row.
- `RolePermissions::allows(user, permission)` answers every check: the person's roles, in their institution's layer on top of the platform's (a system administrator, who belongs to no institution, gets the platform's). The `permission:` route filter is `App\Filters\PermissionFilter`, which asks it.

## Who sets what

| Role | Platform page | Institution page |
|---|---|---|
| `admin` (system administrator) | locked | — |
| `institutional_admin` | yes | — |
| `dean`, `chairperson`, `instructor`, `student`, `parent` | yes | yes, academic permissions only |

Both pages: every role keeps at least one permission, every change is in the audit log as `roles.permission_changed` (with `institution_id` on the institution page), and a change that cannot be audited is undone.

## Only permissions that do something

Each role is offered only the permissions that have an effect for it (`AuthGroups::APPLICABLE`). Any other box shows "Not used by this role", cannot be set, and a stored row for it is ignored.

| Role | Offered |
|---|---|
| student | `courses.view`, `messages.use` |
| parent | `children.view`, `messages.use` |
| instructor | `courses.view`, `courses.manage`, `grading.manage`, `announcements.manage`, `discussions.manage`, `messages.use` |
| chairperson | the instructor's five, `program.monitor`, `followups.manage`, `messages.use` |
| dean | `courses.view`, `department.monitor`, `grades.approve`, `grades.signoff`, `followups.manage`, `department.announce`, `messages.use` |
| institutional_admin | the institution-administration permissions, `courses.view`, `grades.approve`, `grades.signoff`, `institution.roles`, `messages.use` |

`monitoring.manage` was removed (nothing used it).

## What each permission controls

- **`courses.view`**: the course pages and their data (student, instructor, chairperson, dean, institution), and each role's course list. Without it the dean's Apps menu has no Courses entry; the dashboard still opens.
- **`courses.manage`**, **`grading.manage`**, **`announcements.manage`**, **`discussions.manage`**: changes in a course, by part; see `course-access.md`. In a course, what counts is the person's role there, not the groups they hold: an instructor enrolled in someone else's course is a student in it (`CourseMembership::roleIn`). Without one, that part of the course is shown as it is, without its controls, and the change is refused (403). The calendar follows the same rule: "Add Assessment" and "Edit Deadline" need `courses.manage`, "Add Announcement" needs `announcements.manage`.
- **`program.monitor`**: the chairperson's Monitor Program page and its data.
- **`department.monitor`**: the dean's Monitor Department page, its data and the department report.
- **`followups.manage`**: sending a follow-up and passing one down. Replying to and closing a follow-up you received needs none.
- **`department.announce`**: the dean's department announcements.
- **`grades.approve`**: deciding final-grade override requests: the dean's for their department, otherwise the institution's administrators' (`/institution/approvals`). Who decides is `GradeAuthority`; see `institutional-admin-area.md`, "Who decides grade overrides and sign-off". A change for the dean or the institutional administrator moves the waiting requests at once.
- **`grades.signoff`**: signing off finalized grades: the dean's, otherwise the institution's administrators' (`/institution/signoff`). Both Roles pages warn where nobody holds it, or `grades.approve`, in a department.
- **`messages.use`**: the Messages area (course chats, direct messages, staff groups). Held by every institution role, never by the system administrator; the area is also behind the `messages` feature flag, which ships off. Two things are decided by role rather than by this permission: only an institutional administrator reviews message reports (`/messages/reports`) and changes the institution's "students messaging each other" switch, and only a chairperson or dean can start a staff group. See `messages.md`.
- **Messages "Chat controls"** (the Settings tab that switches Reactions, Replies, Forwarding, Bump, Delete for everyone and the lock for a chat) is decided by role, not by `messages.use`: an **instructor** manages the chats of courses they teach; a **chairperson** or **dean** manages course chats in their sub-department or department and staff groups they created; an **institutional administrator** manages any course chat or staff group of their institution. Direct chats have no controls. The chat must also be one the person is in, because every message action resolves the chat through the person's own membership: instructors and students are members of course chats, so a chairperson, dean or administrator who is not a member of a course chat cannot open its controls (they can for staff groups they created). Students and parents never manage a chat. Moderators of a chat also see Remove instead of Report on other people's messages. See `messages.md`.
- **`audit.view`**, **`audit.manage`**: the platform audit log, and its settings.
