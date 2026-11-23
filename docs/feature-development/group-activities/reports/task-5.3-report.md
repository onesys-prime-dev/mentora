# Task 5.3 report: proposal, invitation, leader and rename endpoints and UI

Branch `feat/group-activities-p5-leader`. Nothing is committed.

## Files

Changed:
- `app/Config/Routes.php`: student group gets `POST my-group/vote`, `my-group/rename`, `my-group/propose`, `my-group/invitations/(:num)/accept` and `.../decline`. The instructor and chairperson groups get `GET assessments/:aid/groups/proposals` and `POST groups/groups/:gid/approve`, `.../decline` and `.../leader`. The dean group gets the `GET .../groups/proposals` route only. Each route uses the same filters as the routes next to it. `CoursePermissionFilter` needed no change, because the default rule (`courses.manage`) already covers these paths.
- `app/Controllers/Courses/Groups/StudentGroupController.php`:
  - `show` now adds `myGroup.status`, `leader {id, final, votes:[{candidateId,count}], needed, myVote}`, `canRename`, `canVote` and, while proposed, `proposal.pending` (names).
  - It also adds `signup.minSize/maxSize`, `canPropose`, `invitable [{id,name}]` and `pendingInvitations [{groupId,name,proposer,members}]`.
  - New actions: `vote`, `rename`, `propose`, `accept`, `decline`.
  - Member entries still carry only `id` and `name`.
  - An invitation that is not this student's, or that belongs to another activity, returns 404.
  - `run()` now returns what the change returned (for example `id`).
- `app/Controllers/Courses/Groups/ActivityGroupsController.php`:
  - `show`: each group now has `status` and `leader {id, final}`. The board lists active groups only. Proposed groups appear in the queue, and declined groups are hidden.
  - New: `proposals` (proposalsForConfig, windowSummary with names, minSize, maxSize, closesAt), `approve`, `decline`, `setLeader`.
- `app/Services/Groups/ActivityGroupService.php`: `renameGroup` now hands off to `GroupLeaderService::rename(..., asInstructor: true)`. Staff and student renames therefore share one locked, audited path, and staff skip the student rules.
- `app/Views/pages/courses/features/assessments/partials/student-group-card.php`: adds the Rename group dialog and the Propose a new group dialog (name, invitee checkbox fieldset, size hint). Both use the shared dialog and form-field components.
- `app/Views/pages/courses/features/assessments/partials/group-activity-section.php`: adds the proposals container, `id="ga-board"` as the target of the "place students by hand" link, a Decline dialog that needs a reason, and a Set leader dialog.
- `resources/js/pages/courses/groups/student-card.js`:
  - Leader row: "Leader: name" once final. Before that, "Nominated: X · n of N agreed" with an Agree button, plus a Nominate select and button while the group can vote.
  - Rename (shown only when `canRename`).
  - Invitations list with Accept and Decline.
  - Propose dialog.
  - Proposed waiting state ("Waiting for your instructor to approve this group." and "Invited: ...").
  - Every action sends a toast and a live-region announcement and then refreshes the card. A refusal stays in its dialog.
- `resources/js/pages/courses/groups/activity-panel.js`:
  - Proposals queue (screen 4B) loads only in proposed mode, with loading, error, empty and filled states. It shows the minimum met or below badges, pending invitees, and Approve (only when the minimum is met) and Decline.
  - Summary after the window: "N groups below minimum", "N students ungrouped" and a link to the board.
  - Each group gets a "Set leader" action and a "★ Leader: name" badge.
  - Read-only users (`canWrite` false, which includes the dean) see no write controls.
  - A change to the formation mode reloads the queue.
- `resources/js/pages/courses/groups/api.js`: adds `fetchActivityProposals`.
- `resources/js/pages/courses/groups/detail.js`: a card's `decorate().actions` now also shows on cards that are not locked, next to Delete. Locked cards behave as before.
- Tests: `tests/feature/StudentGroupRoutesTest.php` (+5 tests), `tests/feature/ActivityGroupsRoutesTest.php` (+4 tests), `tests/js/pages/courses/groups/student-card.test.js` (+8), `tests/js/pages/courses/groups/activity-panel.test.js` (+5).

New:
- `tests/e2e/group-activity-leader.spec.js` (propose, accept, approve, vote to leader, leader-only rename, 360 px).

## Commands and results (run from C:\xampp\htdocs\mentora, scratch DB on :3307)

The new tests were written first (red phase):
- `phpunit --filter 'StudentGroupRoutesTest|ActivityGroupsRoutesTest'`: `Tests: 35, Assertions: 213, Errors: 3, Failures: 6` (the routes did not exist yet, so they returned 404).
- `npx vitest run tests/js/.../student-card.test.js`: `Tests 7 failed | 13 passed (20)`. One new negative test, "Rename hidden when canRename false", passed before the change, as expected.
- `npx vitest run tests/js/.../activity-panel.test.js`: `Tests 5 failed | 15 passed (20)`.

After the change (green phase):
- `env 'database.tests.port=3307' 'database.tests.hostname=127.0.0.1' vendor/bin/phpunit --filter 'Group|Assessment'`: `OK, Tests: 1067, Assertions: 3458` (the only warning is that no coverage driver is installed).
- `npx vitest run` (whole suite): `Test Files 118 passed (118), Tests 1205 passed (1205)`.
- `npx eslint` on the changed JS, the two Vitest specs and the new e2e spec: no errors, exit 0.
- `vendor/bin/phpstan analyse` on the two controllers and ActivityGroupService: `[OK] No errors` (6 s).

## Not run
- Playwright: `tests/e2e/group-activity-leader.spec.js` has never been run. The e2e database on :3306 is broken and was not touched. It depends on `group-activity-student.spec.js` TC-GAS-02 having made the lab a group activity. Expect selector fixes on the first real run.
- Manual 360 px, dark mode and axe checks in a browser were not done.

## Deviations and decisions
- `signup.isOpen` still means self-signup is open, because the existing card uses it for "no open groups" and the leave text. The proposal mode uses a new `canPropose` flag instead. The flag is true when the mode is proposed, the window is open, the student is in the pool and ungrouped, and the offering is not finalized.
- `myGroup.canVote` was added (not in the brief) so the card hides Nominate and Agree when the server would refuse them: frozen, finalized, proposed, or the leader is final and `nonleader_can_vote` is off.
- `pendingInvitations` lists only students who are not yet in a group. `members` there is a list of names (strings), as `pendingInvitationsFor` returns them.
- Approve has no confirmation dialog. It only makes the proposal a normal group, which the instructor can still edit. Decline needs a reason, both in the client and in the service.
- The instructor board now shows only `active` groups. Before this change it also drew proposed and declined groups as normal cards.
- "Leader star" is a neutral badge "★ Leader: name" on the card, not a star on the member chip, because the shared board's chips cannot be decorated.
- Colours follow the existing group screens: the same hex utility classes plus `brand` tokens. No new colours were added. Dark mode works only as well as those existing classes already do.
- Nothing here adds submissions, grading or notifications.
