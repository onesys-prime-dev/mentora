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

**The grade report** (`.../grades/report/mine`) is a PDF, only once the grades are final, and every
download is audited (`grades.report_exported`, the parent as actor). It is the student's report
without the item-by-item part (no Points column, no Scores list), so it says no more than the Grades tab.

## How it is enforced

- `CourseMembership` (`app/Services/Courses/`) answers the question, as the role
  named or the role the person is acting as.
- The route filter `coursemember:<role>` (`CourseMembershipFilter`) is on the
  `api/courses` group and on the `courses/(:num)/...` page routes of each area, ahead
  of `assessmentscope`, `closedoffering` and `chairpersoncourseaudit`, which look
  inside the offering.
- `AssessmentScopeService::canAccess` uses the same answer, so a person who is both
  instructor and chairperson is judged as the role they are acting as.

## Keeping it so

`tests/feature/CourseMembershipTest.php` reads the real route table: a course
route added to an area without the filter fails it. It also asks for another
section's tabs, grades (scheme, roster, export) and writes as each role.
