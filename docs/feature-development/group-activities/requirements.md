# Requirements (consolidated draft for approval, Stage 2)

Source of truth for decisions: `decision-register.md` (D1-D27). This file restates them as requirements. If the two disagree, the register wins and the conflict is flagged to the user.

## 1. Scope
- Group sets are reusable templates per course offering (D1). Attaching a set to an activity COPIES its groups, and the activity owns the copy (D2).
- Activity kinds (D12): assignment-style assessments get the full workflow. Ungraded learning items (reading, video, pdf, link, discussion) get grouping only. Group quizzes are out of scope.
- Student pool (D23): only assigned students, or all enrolled/completed students when there is no assignee list.
- Terminology: use "group set" and "activity group" in code and UI. Avoid "section", the messaging `GroupService`/`GroupController`, and Shield "groups".

## 2. Functional requirements
- **FR-1 Group sets.** Instructor and chairperson create, edit and delete sets per offering (max 20). A set has a name, formation mode, size min/max, signup window and defaults (D3, D17, D22). Offering copy carries set structure, never students (D20).
- **FR-2 Formation modes (D3, D17, D18).**
  - A. Manual: list picker plus drag, with a non-drag alternative.
  - B. Auto-generate: by size OR count, random, optional keep-together/keep-apart pairs, leftovers spread within max else left ungrouped, editable preview, never silently replaces existing groups.
  - C. Self-signup: instructor creates groups, students join in an open/close window, with a race-safe capacity check.
  - D. Proposed: a student creates a group and invites others; it becomes active after min size plus instructor approval.
  - Groups below min at close are flagged.
- **FR-3 Membership (D4, D9, D25, D26).**
  - At most one group per student per activity. Ungrouped students are allowed.
  - Free before the group's first submission. After it, frozen except: dropped students are auto-removed, and the instructor can add late enrollees. Anything else needs an instructor unlock with confirmation and audit.
  - The signup window only controls JOINING. Leaving or switching is allowed only until the first submission, in every mode.
  - Groups on learning items never freeze.
- **FR-4 Ungrouped policy (D5).** Per activity: "must join a group" (default, blocked) or "may work alone". Changing it after submissions exist needs confirmation.
- **FR-5 Submission (D6, D11, D27).**
  - Style per activity: shared (any member submits), leader-only, or per-member. Submitter and time are recorded.
  - Style can change until the group's first submission, then needs an unlock.
  - Shared submissions have one deadline, one late flag and one attempt count per group. The instructor may extend a whole group's deadline with a reason. No per-member extensions. Per-member style uses each student's normal deadline.
- **FR-6 Leader (D14-D16).**
  - In leader-only activities, students can rename the group and nominate a leader. The leader is final when a majority of the group agrees. The instructor can set or override it at any time.
  - Leadership is frozen after the first submission. If a finalized leader drops the offering, the next-joined member becomes leader.
  - Once final: leader-only ON means only the leader submits; OFF means anyone submits. Only the leader renames the group.
  - Instructor toggles for non-leaders: rename (default OFF), nominate/vote (default OFF), leave (default ON, pre-submission only).
  - Members always see the submission and their own grade and feedback.
- **FR-7 Grading (D7, D8, D24).**
  - Rubric criteria have a scope, group or individual. A student's score is the group criteria plus their own individual criteria.
  - Single-score activities choose: collective, collective plus per-student adjustment (reason required), or individual. Per-member submissions may also use the average of members' scores.
  - Collective: one rubric score and one feedback thread per group, plus an optional private note per member. Individual: per-student rubric and feedback.
  - A student sees only their own adjustment and reason.
  - Fan-out writes one score per member in ONE transaction and respects the finalized-offering lock.
- **FR-8 Post-grading changes (D10).** A dropped student: instructor chooses keep (default) or excuse. A late enrollee added: grade separately (default, pending) or inherit the group grade. Choice and reason are audited.
- **FR-9 Learning items (D13).** The roster is visible. Completion setting: "any member completes for the group" (one `learning_completions` row per member) or "each member completes their own".
- **FR-10 Roles (D14).**
  - Instructor and chairperson: full access. Dean: read-only. Parent: sees the linked child's group and group grade. Admin: none.
  - Student: views own group, submits, joins/leaves/switches per the rules above.
  - Students see only their own group unless the instructor enables visibility of others.
- **FR-11 Notifications (D19).** Ten events, in-app AND email. Needs a new MailOutbox kind. Batched and deduped. Never leaks other members' adjustments.
- **FR-12 Gradebook and reports (D20).** Gradebook per student with a Group label. Group view only on Grade Work. Exports add a Group column. Imports and reports stay per student.
- **FR-13 UI (D21).** Course "Groups" tab for sets, a "Groups" section in the activity editor, a Grade Work group view, and a student "Your group" card on the activity page.

## 3. Non-functional requirements (D22)
- Limits: 50 groups per activity, 30 members per group, 20 sets per offering (adjustable in the plan).
- Audit every membership, leadership, grading and settings change (`AuditLogger`, `groups.*`, old/new values, reason).
- UI per `docs/ui-guide.md`: loading/empty/error/filled states, 360 px phone layout, keyboard use, non-drag alternative, WCAG-style checks, confirm for irreversible actions.
- Auto-generate and fan-out run synchronously. One transaction per fan-out. Race-safe joins.
- Security: authorization on every route (permission names decided in planning), course and assessment scope checks, CSRF, no cross-group data leaks. Instructor routes must be mirrored in the chairperson group.

## 4. Acceptance criteria (high level; refined per phase in the plan)
1. An instructor can create a set, form groups, and attach it to an assignment as an independent copy. Editing the set later does not change the activity.
2. A student is in at most one group per activity. Joins are race-safe at capacity.
3. Shared submission by any member records submitter and time. Leader-only blocks non-leaders. Per-member works per student.
4. A collective grade fans out to all members atomically. Adjustments require a reason and stay private. Group and individual rubric criteria compute correctly.
5. After the first submission, membership is frozen except dropped-student removal and late-add. Unlock is confirmed and audited.
6. Ungrouped students are blocked, or may work alone, per the setting.
7. Learning-item groups show the roster, and the completion setting writes the correct `learning_completions` rows.
8. Notifications (in-app and email) fire once per event and leak no private data.
9. A finalized offering refuses all group writes.
10. All screens have the ui-guide states and a 360 px layout.

## 5. Dependencies and touch points
`AssessmentSubmissionModel`, `AssignmentSubmissionService`, `GradeWorkService`, `GradebookSyncService`, `AssessmentResultService`, `LearningProgressService`, rubric tables, `assessment_assignees`, `CoursePermissionFilter::RULES`, instructor/chairperson/student route groups, MailOutbox kind enum, `NotificationService`, `AuditLogger`. To check: `GradeSheetImportService`/`GradeSheetExportService` and parent notification rules.

## 6. Deferred to planning or later
Release staging of modes and styles (D3, D6); voting tie and two-member rules (D15); permission names; "apply change to other activities" action (D2); group-aware reports; non-leader draft area; per-member extensions; group quizzes; grade-balanced generation.
