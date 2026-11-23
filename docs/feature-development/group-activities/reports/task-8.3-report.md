# Task 8.3 report: groups on learning items, endpoints and UI (screens 2A for items, 3D)

Status: DONE (see "Not run" and "Deviations").

## What was built
- Endpoints under `/api/courses/:id/learning-items/:itemId/groups...` for instructor and chairperson (GET, attach, settings, detach, recopy, copy-from, add/rename/delete group, add/move/remove member), dean GET only, and student `/my-group` GET plus join/leave/switch. Same route groups and filters as the assessment ones; the controllers check that the item belongs to the offering (unit, module) and answer 404 otherwise; foreign group ids 404; StructureException is 422.
- Reuse by generalizing, not copying: `ActivityGroupsController` and `StudentGroupController` got small overridable hooks (`findConfig`, `extensions`, `dueDate`, `activities`; helpers made protected). `LearningItemGroupsController` and `StudentLearningItemGroupController` extend them and only route the grouping actions (no leader, extension, unlock, late enrollee, proposal routes).
- Service rules (trust boundary):
  - `ActivityGroupService::attachLearningItem` takes optional item settings (`ITEM_SETTINGS`: formation, window, min/max, completion, visibility).
  - Assignment/quiz items get the 422 "Use the assignment's own group settings. ...".
  - Proposed mode is refused, whether asked for or inherited from the chosen set.
  - `ActivityGroupMembershipService::changeSettings` only accepts the item settings for an item config. It refuses `proposed` and an invalid completion mode.
- `LearningProgressService::completeForGroup` now writes an audit row `groups.completed_for_group` (actor = the student who marked it). The student card uses it for "Marked complete by <name> for the group".
- Editor UI: `group-activity-section.php` has a learning-item flavour (`$groupItem`) with these parts:
  - group set picker or start from scratch
  - formation mode without "propose"
  - signup window and min/max
  - a two-option completion radio: "Any member marks it done for the group" / "Each member completes their own" (default "each")
  - the "Students can see other groups" switch
  - the shared board (cards, ungrouped, Move picker plus drag, add/rename/delete)
  - detach confirm

  There are no leader, grading, submission, extension or unlock controls or dialogs. `activity-panel.js` detects `data-learning-item-id`, skips fields and dialogs that were not drawn, and passes no `decorate`, so cards are plain. Dean / finalized: `flags.canWrite` false disables everything (existing behaviour). It is wired in `learning-item-editor.php` for reading/video/pdf/link/discussion and initialized in `learning-item.js`.
- Student UI (3D): `student-group-card.php` has a learning-item flavour. It is drawn on `learning-item-view.php` only for a student when the item uses groups. `student-card.js` shows:
  - "Your group: <name>" and roster chips ("You (name)")
  - under "each", each member's own "Done / Not done yet"
  - under "any", "Any member can mark this done for the group." and the footer label "Mark complete for the group"; once done, "Marked complete by <name> for the group."
  - for an ungrouped student, "You're not in a group yet. You can still complete this item on your own." (not blocking)
  - join, switch and leave (with confirm) when self-signup is open

  `completion.js` dispatches `learning-item:completed`, and the card refreshes quietly. The live region announces changes. The existing Mark complete behaviour is unchanged.
- Privacy: other groups only as `{id, name, members{id,name}}` when `groups_visible`, never with completion status. "each" status is shown only for the student's own group.

## Files
New:
- app/Controllers/Courses/Groups/LearningItemGroupsController.php
- app/Controllers/Courses/Groups/StudentLearningItemGroupController.php
- tests/feature/LearningItemGroupsRoutesTest.php (16 tests)
- tests/js/pages/courses/groups/learning-item-groups.test.js (10 tests)
- tests/e2e/learning-item-groups.spec.js

Modified:
- app/Config/Routes.php
- app/Controllers/Courses/Groups/ActivityGroupsController.php
- app/Controllers/Courses/Groups/StudentGroupController.php
- app/Services/Groups/ActivityGroupService.php
- app/Services/Groups/ActivityGroupMembershipService.php
- app/Services/Courses/LearningProgressService.php
- app/Views/pages/courses/features/assessments/partials/group-activity-section.php
- app/Views/pages/courses/features/assessments/partials/student-group-card.php
- app/Views/pages/courses/features/modules/learning-item-editor.php
- app/Views/pages/courses/features/modules/learning-item-view.php
- resources/js/pages/courses/groups/activity-panel.js
- resources/js/pages/courses/groups/student-card.js
- resources/js/pages/courses/groups/api.js
- resources/js/pages/courses/features/modules/learning-item.js
- resources/js/pages/courses/features/modules/completion/completion.js

## Commands and results
- Red, PHP: `phpunit --filter LearningItemGroupsRoutesTest` gave "Tests: 16, Assertions: 18, Failures: 16" (routes missing, 404).
- Red, Vitest: `npx vitest run tests/js/pages/courses/groups/learning-item-groups.test.js` gave "Tests 10 failed (10)".
- Green, PHP new file: "Tests: 16, Assertions: 149" OK.
- Green, PHP filter `LearningItemGroup|Group|LearningItem|Module|Assessment` (port 3307): first run "Tests: 1683, Assertions: 5840, Failures: 1". That failure was the 8.1 test `testAttachRefusesQuizAndAssignmentItems`, which expects "reading" in the message, so the message now carries both sentences. Rerun: "Tests: 1683, Assertions: 5842" OK. `LearningItemGroup` alone: "Tests: 30, Assertions: 183" OK.
- `npx vitest run` (whole suite): "Test Files 121 passed (121), Tests 1241 passed (1241)", which includes the assessment group panels and student card.
- `npx eslint` on all changed/new JS and the e2e spec: no problems.
- phpstan on the 7 changed/new PHP files: "[OK] No errors".
- The only PHPUnit warning is "No code coverage driver available".

## Not run
- Playwright `tests/e2e/learning-item-groups.spec.js` was written by convention but NOT RUN: the e2e database on :3306 is broken. Expect selector fixes on the first real run.

## Deviations and decisions
- Proposed mode is not offered for items (refused at attach and in settings, with a field error), because proposals bring leader and approval flows that items do not have.
- Assignment and quiz items both get "Use the assignment's own group settings. On a learning item, groups can be used on a reading, video, PDF, link or discussion only."
- The generate-groups dialog is not reused. The assessment panel does not have it (it lives on the Groups tab for sets), so skipped; groups come from a set or are added by hand.
- "Marked complete by" comes from the new `groups.completed_for_group` audit row. Completions made before this change, or a group whose members completed on their own, show "Completed for the group." without a name.
- The 'can leave' toggle is not shown for items. Leaving is always allowed except on a finalized offering.
- The dean has no editor route, so the read-only panel is covered by the dean GET plus `canWrite=false` (Vitest). No dean page renders it.
- The coordinator's `progress.md` is not edited.
