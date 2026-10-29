# Who may open a course

Every course address of the student, instructor and chairperson areas names an
offering (`.../courses/{offering}/...` and `.../api/courses/{offering}/...`).
Putting another section's number there must never show that section.

## The rule

The person must **belong to the offering, in the role of the area they are in**:

| Area | Belongs when |
|---|---|
| `student/...` | enrolled (or completed) in an active or completed offering |
| `instructor/...` | assigned to teach it |
| `chairperson/...` | the offering is in a sub-department they chair |

The institution's administrator is separate: they reach every course of their
own institution through the read-only mirror routes (`InstitutionCourseFilter`).

Anyone else, with a valid account and the right role, gets a **404** before any
controller runs: reads, writes and exports alike. The page does not confirm the
offering exists.

## Parents

A parent belongs to no course. They reach a **child's** courses, read-only, under
`parent/...` (GET routes only, role `parent`, permission `children.view`). The question is
answered by `ParentAccessService` (`app/Services/Parents/`), live from
`parent_student_links`:

- only the parent's own (current) institution counts, and the student must still belong to it;
- a deactivated student stays reachable (their history), a deleted one does not;
- a child's course is reachable when the child is enrolled in it in any status (dropped and
  finished courses stay visible);
- the whole portal is gated by `ParentPortal` (Mentora's `parent.portal` flag AND the
  institution's switch; unreadable means off).

The child is named in the address (`parent/children/{id}/...`); the `parentchild` route
filter answers **404** for anyone the parent is not linked to, ahead of any controller.

**The dashboard** (`ParentDashboardService`, `parent/dashboard` and `parent/children/{id}`)
reads the child through the services the student's own pages use, so the two agree:

- *Courses this term* carry the standing the student sees (percentage, band, "Final" or
  "So far", tag), only once the grading scheme is published. *Past terms* hold dropped,
  withdrawn and finished courses.
- *Needs attention*: "At risk" is the gradebook tag for a running grade below the passing
  mark (INC and Withdrawn replace it); *not handed in* is a published assessment past due
  with nothing submitted (an Excused one is never missing); *final grade available* shows for
  14 days after a grade is finalized.
- *Coming up*: deadlines in the next 14 days, with the child's status and never a score.
- A student who also holds a staff role has their courses listed but no grade or assessment
  read (the shared services would treat them as that staff member). A course without a
  grading scheme shows no standing, and none is created by looking at it.

**The calendar** (`parent/children/{id}/calendar`) is the student calendar page in a
read-only mode (`data-role="parent"` in `calendar.js`): deadlines and announcements only,
nothing to add, edit or delete, and the child's own reminders are never in it
(`ParentCalendarEvents`).

**The course** (`parent/children/{id}/courses/{offering}/{slug}/{tab}`) is the student's course
page in a parent mode (`ParentCourseController`, `pages/courses/view.php` with `courseRole`,
`courseFeatures` and a view-only note). The tab scripts are the student's own, unchanged (they only
treat the role `instructor` specially); each tab's endpoint under `parent/children/{id}/api/courses/{offering}/`
returns `{html}` from `ParentCourseTabs`:

| Tab | A parent sees | Never |
|---|---|---|
| Overview | the course facts: description, teacher, schedule, room, term | progress, activity, class size, links into Modules |
| Announcements | the latest 20 (pinned first): subject, text, author, date | replies, reactions, attachments, a composer |
| Assessments | title, type, due date and a status word | a score, an attempt, feedback, any way into a quiz |
| Grades | overall percentage, band, Final or So far, tag, each category's weight and percentage (under its period when the scheme has periods) | items, manual scores, assessments, points |

Only those four tabs have routes. Modules, Resources, Discussions and every staff route answer
404. The filters run in this order: `parentportal` (switched off: the portal-off page, or a 403 for an
API call), then `parentchild` (the child must be the parent's, and enrolled in the course named in the
address, in any enrolment status). A course with no grading scheme shows no standing and none is
created by looking; a child who also holds a staff role has no grade or assessment data read.

**The student can see who their parents are.** `student/profile` (read only, no write route) shows the
student's name, email and student number and "Parents who can see your progress": the names (never an
email) of the parents who actually have access now, that is `ParentAccessService::parentsOf` (linked,
active, a parent, members of the institution) whose institution has the parent portal on. With none,
or the portal off, it says "No parent can see your progress." The header's "My Profile" opens it (and a
parent's own profile for a parent).

**The parent's profile** (`parent/profile`) is the parent's own account and the only place a parent can
change anything: the email choice for each child (`POST parent/profile/notifications`, behind
`parentportal`) and an emailed password link to their own address (`POST parent/profile/password-link`).
Nothing under `parent/children/...` can be posted to, and a read-only institution refuses both.
Notifications are on the shared `/notifications` page (`docs/notifications.md`, "Parents").

**The grade report** (`.../grades/report/mine`) is a PDF, only once the grades are final, and every
download is audited (`grades.report_exported`, the parent as actor). It is the student's report
without the item-by-item part (no Points column, no Scores list), so it says no more than the Grades tab.

## The role in a course decides (audit 9.1)

What someone may do in a course depends only on their role **in that course**,
never on the groups they hold. Someone who teaches one course and is enrolled in
another is a student in the second: its quiz rules (attempt limit, availability,
visibility, prerequisite locks), the student view of its pages, and the student's
access to its files.

- `CourseMembership::roleIn(user, offering)` answers `instructor`, `chairperson`,
  `student` or null, in that order (only a chair who teaches can be two).
- On a course route, `CourseMembershipFilter` records the offering and the area's
  role in the shared `courseContext` service, so checks deep in a page
  (`CourseViewer::seesInstructorView`, `CourseViewer::isStudentHere`) answer for
  this course without being handed it. Outside a course they fall back to groups.
- Lists across courses (course cards, dashboard widgets) follow the role the
  signed-in person is acting as (`CourseService::listRole`).

## Nobody is both student and staff of a course (9.2–9.7, D7, D10)

`CourseRoleConflicts` gives the reason, the words after the person's name, and
every writer refuses with it: the enrolment page (skipped with the reason), a
status change back to enrolled, the SIS import and CSV upload (the row fails
alone), instructor, chairperson and dean assignment, and granting the
institutional administrator role.

| Refused | Because the person | Words |
|---|---|---|
| enrolling in a course | has an instructor assignment on any of its sections, any term | "teaches or has taught this course" |
| | has (or had) a chairperson assignment on its sub-department | "chairs or has chaired the sub-department of this course" |
| | is (or was) the dean of its department | "is or was the dean of this course's department" |
| | is an administrator of its institution | "is an administrator of this institution" |
| teaching a section | is enrolled (or completed) in the same course this term | "is enrolled in this course this term" |
| chairing a sub-department | is enrolled in one of its courses | "is enrolled in a course of this sub-department" |
| being dean of a department | is enrolled in one of its courses | "is enrolled in a course of this department" |
| the administrator role | is enrolled in one of the institution's courses | "is enrolled in a course of this institution" |

An enrolment that ended (completed, dropped, withdrawn) never blocks a staff role
in a later term: a former student may teach or chair the course later. Removing
an instructor by hand deletes the assignment, so it no longer counts as "taught";
roles keep no history, so a former administrator is not known. The migration
`EndCourseRoleConflicts` dropped the enrolments that already clashed (the work
kept, the staff role kept, audited as the system).

## How it is enforced

- `CourseMembership` (`app/Services/Courses/`) answers the question, as the role
  named or the role the person is acting as.
- The route filter `coursemember:<role>` (`CourseMembershipFilter`) is on the
  `api/courses` group and on the `courses/(:num)/...` page routes of each area, ahead
  of `assessmentscope`, `closedoffering` and `chairpersoncourseaudit`, which look
  inside the offering.
- `AssessmentScopeService::canAccess` uses the same answer, so a person who is both
  instructor and chairperson is judged as the role they are acting as.
- Being in the course is not all: a change asks the permission of the part of the
  course it belongs to (`CoursePermissionFilter`, on the instructor's and
  chairperson's `api/courses` groups), and the course screens hide what the person
  may not change (`CourseAbilities`, also `coursesConfig.can` for the scripts).
  Each institution can take these away from its roles (`roles-and-permissions.md`).

  | Change | Needs |
  |---|---|
  | announcements: post, edit, delete, pin, attachments | `announcements.manage` |
  | discussions: pin, lock; delete someone else's post or reply; mark someone else's answer | `discussions.manage` |
  | the gradebook, grading work and feedback, the curve (`grades/…`, `submissions/…`) | `grading.manage` |
  | any other course change: modules, units, items, assessments, rubrics, questions, generation, import | `courses.manage` |
  | replying, reacting, posting and editing one's own discussion | nothing |

## Keeping it so

`tests/feature/CourseMembershipTest.php` reads the real route table: a course
route added to an area without the filter fails it. It also asks for another
section's tabs, grades (scheme, roster, export) and writes as each role.
