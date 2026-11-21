# Group activities

Instructors can put students into groups for an assignment, and the group hands in one piece of work, is graded once, and each member gets their own score in the gradebook. The same groups can be used on a reading, video, PDF, link or discussion item, where they only show a roster and can share completion. Groups are made from reusable **group sets** on the course's Groups tab and are copied into each activity, so changing a set later never changes an activity that already used it. Group quizzes are not supported.

## Who can do what

| Role | What they can do |
|---|---|
| Instructor | Everything: create and edit sets, form groups in any mode, attach groups to an activity, approve proposals, set a leader, extend a group's deadline, unlock a frozen group, add late enrollees, grade groups, decide for dropped students. |
| Chairperson | The same as the instructor, in the courses they chair. |
| Dean | Read only. Sees the Groups tab, and on an assignment's view page a read-only Groups section with groups, work, grades, adjustments and notes. No write button and no write route. |
| Parent | Read only, for a linked child. On the Assessments tab of the child's course, a group assignment shows the child's group (first names of the members) and, once graded, the child's own grade, its group and individual parts, and the child's own adjustment and reason. Never a teammate's data, the private note, the feedback or the work. A plain assignment still shows no score on that tab. |
| Student | Sees their own group, joins, switches and leaves (until the group's first submission), proposes a group and answers invitations, votes for a leader, renames the group when allowed, submits as the style allows, sees their own grade and feedback. Sees other groups only when the instructor turns that on. |
| Administrator | Nothing special. No group screens. |

## Concepts

**Group set.** A template of groups for one course (up to 20 per course). It has a name, a formation mode, a smallest and largest group size, and, for sign-up modes, an open and close time. A set holds group names and members, but it is only a template.

**Activity groups.** Attaching a set to an assignment copies its groups into the activity. The activity owns the copy: later edits to the set do not change it, and edits to the activity do not change the set. An activity can also start from scratch, re-copy from its set, or copy from another activity (only while no group has submitted). Only the students who are assigned to the activity (or all enrolled students, when it has no assignee list) can be placed.

**Formation modes.** Chosen per set, and shown on the activity too.

| Mode | How groups are formed |
|---|---|
| Manual ("Instructor places students") | The instructor moves students with a Move picker (or drag). Dragging is an extra: the picker works on phones and by keyboard. |
| Auto-generate | On the Groups tab: by group size or by number of groups, random, with optional keep-together and keep-apart pairs. Leftover students are spread into groups within the maximum, otherwise left ungrouped. A preview is shown first, with "Nothing has been saved yet". Replacing existing groups needs a confirmation. |
| Self-signup ("Students sign up") | The instructor makes the groups, students join during the open window. A join is checked for room under a lock, so two students cannot take the last seat. |
| Proposed ("Students propose groups") | A student creates a group and invites others. Invitees accept or decline. Once the group has at least the smallest size, the instructor approves it (a reason is needed to decline). Not offered on learning items. |

**Submission styles.** Chosen per activity.

| Style | Meaning |
|---|---|
| Shared | Any member can submit for the group. One deadline, one late flag and one attempt count per group. The submitter and time are recorded. |
| Leader-only | Only the leader can submit, once a leader is final. Before a leader is final, any member can. |
| Per-member | Each student submits their own work with their own deadline and attempts. The group still exists for grading and roster. |

**Grading modes.** For a rubric, every criterion has a scope. A **group** criterion is scored once for the whole group, and an **individual** criterion is scored per member. A student's score is the group criteria plus their own individual criteria. For a single score (no rubric) the instructor chooses:

| Mode | Result |
|---|---|
| Collective | One score for the group, the same for every member. |
| Collective with adjustment | The group score plus a per-member adjustment. A reason is required for every non-zero adjustment. A student sees only their own adjustment and reason. |
| Individual | Each member gets their own score. |
| Average of members (per-member style only) | Everyone gets the average of the scores of the members who handed in. |

Collective grading also gives one feedback thread per group, plus an optional private note per member that only that member sees. Scores are clamped between 0 and the activity's total points.

**Freeze and unlock.** A group is free to change until its first submission, in every mode. After that it is frozen: no joining, leaving, moving, deleting or changing the submission style or formation mode. Two things still happen: a student who drops the offering is removed, and the instructor can add a late enrollee. Anything else needs the instructor to **unlock** the group, with a required reason and a confirmation. Renaming stays available. The signup window only controls when students can join.

**Leader and voting.** Members nominate a leader and vote. A leader becomes final when more than half of the current members agree (more than half means half the members, rounded down, plus one). Votes from people who left do not count. The instructor can set or override the leader at any time. If a final leader leaves the offering, the member who joined next becomes the leader with no new vote. Once a leader is final, only the leader can rename the group, and the instructor has toggles for the others: rename (off by default), nominate and vote (off by default), leave (on by default, before the first submission only). Leadership is frozen with the group.

**Learning items.** A reading, video, PDF, link or discussion item can use a group set for a roster. Groups on an item never freeze, and members can change at any time. The instructor picks a completion mode: "Each member completes their own" (default) or "Any member marks it done for the group", which marks it complete for every member. A member who leaves keeps their completion, and a member who joins a completed group gets one too. The student card says who marked it complete, for completions made after this feature was added.

## The rules

| Rule | Value |
|---|---|
| Groups per activity | At most 50 |
| Members per group | At most 30 (a set's largest size defaults to 30, smallest to 1) |
| Group sets per course | At most 20 (archived sets do not count) |
| Groups per student per activity | One. Ungrouped students are allowed. |
| Signup window | Controls only joining. Needs a close time after the open time. |
| Leaving or switching | Only until the group's first submission, in every mode. Also needs "non-leaders can leave" on. |
| Learning items | Leaving and joining are always allowed, with no freeze. |
| Ungrouped policy (per activity) | "Must join a group" (default): the student cannot submit and sees why. "May work alone": they submit on their own. Changing it after submissions exist asks for confirmation. |
| Group below the smallest size | Flagged for the instructor, and the instructor is told when the window closes. |
| Dropped student, group not graded | Removed from the group automatically. |
| Dropped student, group already graded | Removed; the instructor chooses **keep** the score (default) or **excuse**, with a reason. |
| Late enrollee added, group not graded | Added, graded with the group. |
| Late enrollee added, group already graded | Shown as pending. The instructor chooses **grade separately** (default) or **inherit** the group grade, with a reason. |
| Deadline extension | By the instructor, for a whole group, with a reason. Shared and leader-only only. No per-member extensions. Per-member style uses each student's own deadline. |
| Finalized offering | Every group write is refused, and the Groups tab says why. |
| Audit | Every membership, leader, settings, grading, unlock and extension change is recorded with old and new values and the reason. |

## Notifications

Ten events are sent in the app and by email. Each person is told once per event (a repeated action does not tell them again), and the person who caused the event is not told.

| # | Event | Told |
|---|---|---|
| 1 | Placed in a group, or groups saved or copied | The placed students |
| 2 | A member joined or left, or the leader changed | The group's other members (all members for a leader change) |
| 3 | A leader vote started | The other members (only the vote that opens a round) |
| 4 | The group submitted | The other members (shared and leader-only styles) |
| 5 | Graded, or a member's score changed | Each graded member |
| 6 | Signup closes within 24 hours | Ungrouped students, while the window is open |
| 7 | A proposal is waiting for approval | The course's instructors |
| 8 | Signup closed with groups below the minimum or students ungrouped | The instructors (for windows closed in the last 7 days) |
| 9 | A group's deadline was extended | The group's members (the reason is not included) |
| 10 | A change after the first submission (unlock, late enrollee, dropped student) | The instructors and the group's members |

The grade notice has the same words for everyone and carries no score, adjustment, reason, note or name. It links to the assignment page, where each student sees only their own result. Events 6 and 8 are found by the every-minute job, `php spark exports:run`, which also sends the email. Group emails join a person's normal outbox email under "About your groups". A notification failure never fails the action that caused it. A student removed by an instructor is not told, because removal is not one of the ten events.

Parents get no group-specific notice. A group grade reaches the running grade, so the existing at-risk and final-grade notices include it. The missing-work notice was fixed so a teammate of a shared hand-in is no longer reported as missing.

## When a student drops

When enrolment changes to dropped or withdrawn, from the enrolment screens, a class section change or an SIS import, the student is taken out of every group in the offering. If the group is frozen, the instructors and members are told (event 10). If the group was graded, the instructor decides keep or excuse. A final leader who drops passes leadership to the next-joined member. If the offering is already finalized, the drop is not applied to groups (the change is refused and logged, so the group rows stay).

## Gradebook

The gradebook stays per student. A group fan-out saves one score row per member in a single transaction: all members are graded or none. A member's gradebook score is their own final score. A pending member (late enrollee, or a reopened group) shows no score, never zero. An excused member is counted as excused, the same as an excused manual item. The student's result page shows the group part, their individual part, each criterion with its scope, and their own adjustment, reason and private note. Reopening grading on a graded group clears the final scores but keeps notes and adjustments. A group submission cannot be graded from the ordinary per-student Grade Work page: it is graded from the group view. An ungrouped student working alone, under the "may work alone" policy, is still graded on the per-student page.

## Copying an offering

Copying an offering carries the active group sets and their groups, as empty groups, with sign-up times shifted like the other dates. It never carries members, leaders, activity groups, scores, invitations, votes or extensions. Archived sets are not copied. Copied assignments start with no groups, and their rubric criteria keep their scope.

## Known limits and deliberate non-goals

- No group quizzes (the quiz engine is per student).
- No per-member deadline extensions.
- No grade-balanced group generation. Generation is random, with keep-together and keep-apart pairs.
- No draft area for non-leader members.
- No Group column in exports (decision D20a, still open for review). There is no per-assignment export. The course grade sheet round-trips through import and refuses an added column. The import never writes group scores.
- Parent tab: group grades show only for group activities, so a group row shows a grade while a plain assignment row does not.
- The dean has no Grade Work page. Their view is the read-only Groups section on the assignment view page.
- "Grade separately" for a late enrollee is recorded in the audit only. The member stays pending, and the dialog is offered again after a reload until they are graded.
- A student's hand-in is not blocked on a finalized offering. It never was, for any assignment. Such a hand-in still freezes its group.
- Joining and switching work only in the self-signup mode.
- Proposed mode is refused on learning items. The "non-leaders can leave" toggle is not shown there.
- A parent of a child removed from a group after grading sees no group line for that row, though the gradebook keeps the score.
- An instructor-set leader with no votes stays until votes change. A destination group is not re-counted for votes after a move.
- Auto-generate notifications cost about two small queries per student. Fine for a class, to be batched if it becomes slow.
- The adjustment reason is written for the student, and a parent of that student can read it. Tell instructors.

## Where the code lives

| Part | Files |
|---|---|
| Services | `app/Services/Groups/`: `GroupSetService`, `GroupMembershipService`, `GroupGeneratorService`, `ActivityGroupService`, `ActivityGroupMembershipService` (freeze, unlock, drop, late enrollee, settings), `GroupSignupService`, `GroupProposalService`, `GroupLeaderService`, `GroupSubmissionResolver`, `GroupExtensionService`, `GroupGradingService`, `GroupNotifier`, `GroupLimits` |
| Touch points | `AssignmentSubmissionService`, `GradeWorkService`, `AssessmentResultService` (`memberAttempts`), `GradebookSyncService`, `LearningProgressService`, `RubricService`, `AssessmentFeedbackService`, `FileAccessService`, `EnrolmentService::setStatus`, `SisImporter`, `OfferingCopyService`, `ParentNotifier`, `ParentCourseTabs`, `MailOutbox`, `NotificationTriggers` |
| Controllers | `app/Controllers/Courses/Groups/`: `GroupsTabController`, `GroupSetsController`, `ActivityGroupsController`, `GroupGradeController`, `LearningItemGroupsController`, `StudentGroupController`, `StudentLearningItemGroupController` |
| Routes and permissions | `app/Config/Routes.php` (instructor and chairperson full, dean GET only, students their own group routes), `CoursePermissionFilter::RULES` (`group-sets` needs `courses.manage`, `group-grading` needs `grading.manage`) |
| Views | `app/Views/pages/courses/features/groups/`, `.../assessments/partials/group-activity-section.php`, `student-group-card.php`, `group-grading-frame.php`, `group-grade-work.php`, `score-panel.php`, `app/Views/pages/parent/course/assessments.php` |
| JavaScript | `resources/js/pages/courses/groups/`: `index`, `detail`, `board`, `generate`, `activity-panel`, `student-card`, `grading`, `api`, `skeleton` |
| Migrations | `2026-12-01-000001` to `000011`: group sets, student groups, members, activity configs, config foreign keys, invitations, leader votes, submission group columns and extensions, rubric criterion scope, group member scores, the `group` mail kind |
| PHP tests | `tests/database/Group*Test.php`, `ActivityGroup*Test.php`, `LearningItemGroupTest.php`, `GroupGradebookTest.php`, `GroupNotifierTest.php`, `tests/feature/Group*Test.php`, `ActivityGroupsRoutesTest.php`, `StudentGroupRoutesTest.php`, `LearningItemGroupsRoutesTest.php` |
| JavaScript tests | `tests/js/pages/courses/groups/` |
| Browser tests | `tests/e2e/groups-tab.spec.js`, `group-activity.spec.js`, `group-activity-student.spec.js`, `group-activity-leader.spec.js`, `group-grading.spec.js`, `group-readonly.spec.js`, `learning-item-groups.spec.js` (never run, see the test cases) |
| Manual cases | `docs/test-cases/p3-group-activities.md` |
| Design record | `docs/feature-development/group-activities/` |

## Operational notes

- The mail outbox has a new kind, `group` (migration `2026-12-01-000011`). Its down step deletes the group rows before restoring the old list. Group rows are merged into the person's normal email and add no second in-app notice.
- Nothing new to schedule. The window-closing and window-closed notices run from the existing every-minute job, `php spark exports:run`. Without that job, events 6 and 8 never fire.
- Run `php spark migrate` to get the eleven migrations.
- The Playwright specs for this feature have not run against a real browser. Run them, and the manual cases, before release.
