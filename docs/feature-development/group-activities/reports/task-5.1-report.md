# Task 5.1 report: GroupProposalService

Status: DONE_WITH_CONCERNS

Files
- app/Database/Migrations/2026-12-01-000006_CreateStudentGroupInvitations.php (new, reversible)
- app/Services/Groups/GroupProposalService.php (new)
- tests/database/GroupProposalServiceTest.php (new, 15 tests)
- tests/database/GroupSignupServiceTest.php (added testProposedAndDeclinedGroupsAreNotJoinable)
- tests/database/GroupSchemaTest.php (testMigrationRollsBack now also rolls back and recreates student_group_invitations; otherwise dropping student_groups would hit the new FK)

TDD
- Red: "Tests: 15, Assertions: 0, Errors: 15" (class not found)
- First green run had 1 failure from my own test (an invitee with a pending invite is still ungrouped); corrected the test expectation.
- Green: "OK, Tests: 398, Assertions: 1689" (filter GroupProposal|GroupSignup|ActivityGroup|GroupSchema|Group)

Notes / concerns
- GroupSignupService needed no code change: its lock() already refuses non-active groups. Only a test was added.
- decline() marks only PENDING invitations declined (accepted ones are removed with the members; their invitation row stays 'accepted' as history).
- Declined groups do not count toward the 50-group cap.
- Messages for invitee violations are new text ("Everyone you invite must be part of this activity.", "Someone you invited is already in a group."); window/full wording reused.
- Concurrent propose races on the same student are caught by the unique key and surface as the generic "could not be saved" message.
