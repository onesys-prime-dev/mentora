# Task 8.1 + 8.2 report: groups on learning items

Status: DONE. Red: "Tests: 14, Assertions: 0, Errors: 14" (attachLearningItem undefined). Green: 678 tests, 2711 assertions, OK (filter LearningItemGroup|ActivityGroup|GroupSignup|GroupProposal|GroupLeader|LearningProgress|Group). phpstan not run.

Changes
- ActivityGroupService: attachLearningItem, findByLearningItem; attachAssessment tail extracted into private attach() (behaviour unchanged). pool() already safe for item configs.
- ActivityGroupMembershipService: freezeGroup no-op for item configs (so every frozen check never fires); onGroupMemberAdded hook after add() and moveMember().
- GroupSignupService: hook after join and switchTo. GroupProposalService: hook after an accepted invitation.
- LearningProgressService: completeForGroup (transaction, upsert), onGroupMemberAdded; markComplete calls completeForGroup.
- tests/database/LearningItemGroupTest.php (14 tests).
