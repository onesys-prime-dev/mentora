# Task 6.1 report: submission resolver, extension service, schema

Status: DONE

## Files
- app/Database/Migrations/2026-12-01-000008_AddGroupToAssessmentSubmissions.php (new; group_id, submitted_by_user_id with FKs SET NULL, uq_submission_group_attempt, group_extensions table; down() reverses all)
- app/Models/Assessments/AssessmentSubmissionModel.php (allowedFields + group_id, submitted_by_user_id)
- app/Services/Groups/GroupSubmissionResolver.php (new)
- app/Services/Groups/GroupExtensionService.php (new)
- tests/database/GroupSubmissionResolverTest.php, tests/database/GroupExtensionServiceTest.php (new)

## TDD
Red: `Tests: 20, Assertions: 0, Errors: 20` (class not found).
Green: `Tests: 538, Assertions: 2092` OK (filter Group|AssignmentSubmission|GroupSchema...), the existing AssignmentSubmission tests pass unchanged.

## Notes
- GroupSchemaTest rollback test needed no change (it does not cover the new migration).
- Shared styles (shared, leader_only) use the group's rows; per_member uses the student's own rows (group_id NULL).
- canSubmit: a student with a proposed group and policy solo is still refused (needs_group); a student with no membership and policy solo is allowed.
- testDuplicateGroupAttemptRefusedByIndex tolerates a thrown or swallowed DB error (db_debug off in tests) and asserts the second row was not stored.
- Leader-only message uses the leader's users.username.
- Extension: must be later than max(due date, existing extension), or now when neither exists.
- AssignmentSubmissionService, controllers and views untouched; nothing committed.
