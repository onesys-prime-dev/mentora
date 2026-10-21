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

Branch 1 of the parent portal builds this foundation and the landing page; the course
workspace for a parent (a child id in the address, four tabs) comes in a later branch.

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
