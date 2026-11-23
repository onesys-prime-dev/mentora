# Task 1.3 report: group-set routes, filter rule, controller

Status: DONE

Files
- app/Controllers/Courses/Groups/GroupSetsController.php (new)
- app/Config/Routes.php (group-sets routes: full set in instructor and chairperson groups, GET list and detail in dean group, none in student)
- app/Filters/CoursePermissionFilter.php (rule `#^group-sets(/|$)#` => courses.manage)
- tests/feature/GroupSetsRoutesTest.php (new, 10 tests)

TDD
- Red: `Tests: 10, Assertions: 16, Failures: 8` (routes did not exist, 404 instead of 200).
- Green: GroupSetsRoutesTest 10/10.
- Regression run (GroupSetsRoutesTest, Group Schema/SetService/MembershipService tests, CoursePermissionFilterTest, DeanCourseViewTest incl. "no route changes anything for dean", ChairpersonCourseAccessTest): `OK, Tests: 86, Assertions: 297`.

Notes
- `:num` in /api/courses/:id/... is the offering id; sets/groups of another offering give PageNotFoundException (404). Non-numeric ids do not match a route (404).
- StructureException => 422 {success:false, message, errors}.
- Student has no route (404 in student area; instructor area refused by role filter with a redirect).
