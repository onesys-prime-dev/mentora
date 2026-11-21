# Task 9.1: Group notifications (D19, FR-11)

## What was built

- `app/Services/Groups/GroupNotifier.php` is the only place that picks recipients and writes the words for the ten group events. For each event it:
  - stores one in-app notice per person, through `NotificationService::sendToMany`, so a group of any size takes a few queries;
  - adds one `mail_outbox` row of kind `group` per person who was newly told. The detail is "<course label>: <body>". `exports:run` merges these into the person's normal outbox email, under "About your groups:".
- Every dedupe key names the event, the group or activity, and the round, for example `groups.graded:<assessment>:<group>:<graded_at>`. Running a hook again tells nobody twice. The email row is written only for people the in-app step newly told.
- The person who caused the event is never told.
- Each event site gets one guarded line: `GroupNotifier::hook(fn (GroupNotifier $n) => ...)`. `hook()` catches any `Throwable` and logs it, so a notification failure never fails the action. Tests can swap in a failing notifier through `GroupNotifier::$instance`.
- Migration `2026-12-01-000011_AddGroupKindToMailOutbox` adds `group` to the `mail_outbox.kind` ENUM. Its `down()` deletes the group rows first, then restores the old ENUM.
- `MailOutbox::GROUP` was added to `KINDS`, and `message()` lists group lines. `MailOutbox::notify()` ignores group rows, so the email adds no second in-app notice.
- The two scheduled events (6 and 8) run through `NotificationTriggers::groupWindows($now)`, which calls `GroupNotifier::windows()`. That runs every minute from `exports:run`, guarded by try/catch. `subscriptions:sync` runs only once a day, which is too coarse for a "closes within 24 h" notice.
- Notification types: `groups.placed`, `groups.member_changed`, `groups.leader`, `groups.submitted`, `groups.graded`, `groups.window_closing`, `groups.proposal`, `groups.window_closed`, `groups.extended`, `groups.unlocked`.
  - The codebase has no type registry or label map. Types are free strings, and the bell only special-cases `message`. So there was nowhere to register them.
- No per-user opt-outs exist for non-parents (see `docs/notifications.md`, "Not here yet"), so there were none to respect. Muted outbox runs (`MailOutbox::context`) are respected automatically.
- Notices about finished actions still send on finalized offerings. The hooks run after the action; refusals happen earlier.

## Event to hook table

| # | Event | Hook site(s) | Told | Dedupe round |
|---|---|---|---|---|
| 1 | Placed / groups saved | `ActivityGroupMembershipService::add` (addMember); `moveMember` (placed in the new group); `GroupSetService::saveGenerated`; `ActivityGroupService::attach` (when a set is copied), `replace` (copyFromActivity, recopy); `GroupProposalService::approve` | the placed students | group id |
| 2 | Member joined, left, or leader changed | `GroupSignupService::join/leave/switchTo`; `ActivityGroupMembershipService::add/moveMember/removeMember`; `onStudentDropped` (group not frozen); `GroupProposalService::respondToInvitation` (accept); `GroupLeaderService::setLeader`; `GroupLeaderService::emit` (leader finalized or passed by votes or membership) | the group's other members (all members for a leader change) | joined: `joined_at`; left: minute; leader: `leader_finalized_at` |
| 3 | Leader vote started | `GroupLeaderService::vote` (nominate uses it) | the other members; only the vote that opens a round | MIN(`voted_at`) of the group's votes |
| 4 | Group submitted | `AssignmentSubmissionService::submit` (shared or leader-only styles) | every other member | submission id |
| 5 | Graded, or a member's score changed | `GroupGradingService::gradeGroup` (graded members); `resolveLateAdd` with inherit (that member) | each graded member | MAX(`group_member_scores.graded_at`) |
| 6 | Sign-up closing in 24 h | scheduled: `NotificationTriggers::groupWindows` | ungrouped students in the pool (`GroupProposalService::windowSummary`), only while the window is open | config id + `signup_closes_at` |
| 7 | Proposal awaits approval | `GroupProposalService::propose` and `respondToInvitation` (accept), once members are at least `min_size` | the offering's instructors (`instructor_assignments`), each linked into their own area (instructor or chairperson) | group id |
| 8 | Sign-up closed with groups below the minimum or students ungrouped | scheduled: `NotificationTriggers::groupWindows`, for windows closed in the last 7 days | instructors | config id + `signup_closes_at` |
| 9 | Deadline extended | `GroupExtensionService::extend` | all members (the reason is never included) | group + assessment + `until_at` |
| 10 | Changed after the first submission | `ActivityGroupMembershipService::unlock`; `add` with a late enrollee on a frozen group; `onStudentDropped` on a frozen group | instructors and the group's members (not the dropped student) | old `frozen_at`, or the student id |

The grade notice uses the same words for everyone: 'Your group's work "<title>" was graded. See your result.'
- The title, body, link and outbox detail carry no amount, reason, note, name or member id.
- The link is the assignment page, where each student sees only their own result.
- This is asserted by `testGradedNoticeHasNoAdjustmentOrOtherMemberData`.

## Parent finding

- **Parents do not get group-grade notices today, and they do not get per-assessment grade notices of any kind.** `ParentNotifier` tells parents about:
  - final grades (at finalization);
  - INC, Withdrawn and At risk (all from `gradebook_records`);
  - missing work;
  - announcements.
- A group grade reaches parents only indirectly, through the same path as any grade:
  - The group fan-out writes each member's `gradebook_item_scores`.
  - That feeds the running grade, so the At risk notice and the final-grade notice reflect group work.
- No group-specific parent text was added, as instructed.
- **Bug fixed:** `ParentNotifier::missingWork` decided "not handed in" from the student's own `assessment_submissions` row.
  - A shared group hand-in is one row under the submitter's id, so the teammates' parents were wrongly told the work was missing.
  - Each candidate item is now checked through `GroupSubmissionResolver::sharedGroup` and `submissionFor`. A finished shared hand-in counts for every member.
  - Per-member style is unchanged.
  - No other missing-work check reads submissions this way: grep found only this one.
  - Test: `testParentNotifierDoesNotReportTeammateAsMissing`.

## Tests

`tests/database/GroupNotifierTest.php` has 21 tests:
- one or more tests per event, covering recipients, a single in-app row (with a dedupe check on repeat), the group outbox row, and that the actor is excluded;
- `testGradedNoticeHasNoAdjustmentOrOtherMemberData`;
- `testNotificationFailureNeverBreaksTheAction`;
- `testBulkAutoGenerateNotifiesAllPlacedStudentsOnce` (40 students);
- `testWindowClosingTriggerDedupedAndOnlyForUngrouped`;
- `testWindowClosedSummaryToInstructors`;
- `testMigrationAddsGroupKind` (checks that the migration is reversible);
- `testGroupRowsAreEmailedAsOnePlainMessage`;
- `testParentNotifierDoesNotReportTeammateAsMissing`.

Red phase:
- First run: `Tests: 21, Assertions: 0, Errors: 21` (`Class "App\Services\Groups\GroupNotifier" not found`).
- After the notifier existed, the parent test was still red: `a teammate of a shared hand-in has nothing missing / Failed asserting that an array does not contain 165.` That was before the `ParentNotifier` fix.
- The same run also caught a real bug: the second vote of a round told the first voter. It was fixed so that only the vote opening a round tells anyone.

Verification on the scratch database (port 3307):
- `--filter 'GroupNotifier|Notification|MailOutbox|Parent|Group'`: 1304 tests, 1 failure.
  - The failure is `ParentProfilePageTest::testWhilePortalIsOffTheAccountStaysAndTheChildrenGoAway`. The header shows another person's name ("Ann Lee") as the signed-in parent.
  - It passes when run alone, and passes together with the new parent test.
  - It still fails in a `Parent` run that leaves out `GroupNotifierTest` (370 tests, 1 failure).
  - So it depends on test order and does not come from this change: the profile page was not touched.
- `--filter 'Assessment|Grade'`: 1887 tests, all pass.
- phpstan on the changed PHP files: no errors (29 s).

## Concerns and limits

- Outbox rows are written one `MailOutbox::tell()` per person, about 2 small queries each. Auto-generating 40 students costs about 80 small queries plus one batched in-app insert per group. Upgrade to a batch insert in `MailOutbox` only if that becomes slow.
- A "left" notice is deduped per minute: a student who leaves, rejoins and leaves again within one minute is told once.
- Placing students in a **set** (`saveGenerated`) tells them even though sets are templates. The brief asked for this. The link opens the course page.
- `removeMember` by an instructor tells the remaining members, but not the removed student. Removal is not one of the ten events.
- Hooks run after the action's own transaction commits. `onStudentDropped` may run inside the enrolment code's transaction.
  - A database error inside the notifier there would mark the outer CI4 transaction failed, even though the exception is caught. That is unlikely, because the notifier only reads and inserts plain rows.
