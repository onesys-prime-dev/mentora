# Task 5.2 report: GroupLeaderService

Status: DONE (uncommitted).

Files
- app/Database/Migrations/2026-12-01-000007_CreateStudentGroupLeaderVotes.php (new, reversible)
- app/Services/Groups/GroupLeaderService.php (new): vote, nominate, setLeader, rename, membershipChanged, leaderState, leaderStateFor
- tests/database/GroupLeaderServiceTest.php (new, 20 tests)
- tests/database/GroupSchemaTest.php: testMigrationRollsBack now also drops/recreates student_group_leader_votes (listed last so FKs resolve)
- Wiring (one `(new GroupLeaderService())->membershipChanged(...)` line each): ActivityGroupMembershipService::removeMember ($from), ::moveMember ($from), ::onStudentDropped (each row with config_id, also frozen groups); GroupSignupService::leave ($groupId), ::switchTo ($from)

Tests
- Red: `ERRORS! Tests: 20, Assertions: 0, Errors: 20` (class not found)
- Green: `OK, but there were issues! Tests: 418, Assertions: 1738` (filter Group*, only the coverage-driver warning)

Design notes
- Majority needed = floor(members/2)+1. Votes are only counted from and for current members.
- Recount: a candidate with a majority becomes final leader. With no majority, a leader who has votes is unfinalized (cleared); an instructor-set leader with zero votes stays.
- Leader no longer a member and no majority: next-joined member (joined_at, id) is promoted and finalized (groups.leader_passed); no members clears the leader. A frozen group only loses members through a drop, so the "unfrozen OR dropped" condition needs no separate flag.
- Destination groups of move/switch are not recounted (only source groups, as specified).
- Audits written after commit; system-triggered ones use actor 0.
