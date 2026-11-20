# Task 3.1 report: ActivityGroupService attach and copy

Status: DONE_WITH_CONCERNS (uncommitted)

## Files
- app/Database/Migrations/2026-12-01-000004_CreateActivityGroupConfigs.php (new)
- app/Database/Migrations/2026-12-01-000005_AddConfigForeignKeysToGroups.php (new; raw ALTER TABLE, named FKs fk_student_groups_config / fk_student_group_members_config, down drops them)
- app/Models/Groups/ActivityGroupConfigModel.php (new)
- app/Services/Groups/ActivityGroupService.php (new)
- tests/database/ActivityGroupServiceTest.php (new, 19 tests)
- tests/database/GroupSchemaTest.php (modified, see concerns)

## TDD
- Red (service moved away, tests written): `Tests: 19, Assertions: 0, Errors: 19` (Class "App\Services\Groups\ActivityGroupService" not found).
- Green: `Tests: 73, Assertions: 464, PHPUnit Warnings: 1` (warning is only "no code coverage driver") for the full requested filter.

## Behaviour
- attachAssessment: type 'quiz' (case-insensitive) refused; one config per assessment; set from other offering refused; set with more than 50 groups refused; finalized refused; one transaction; copies groups and members filtered through pool(); set's formation_mode/min/max/window override settings; audit groups.activity_attached.
- copyFromActivity / recopy / detach: finalized refusal, refused when any of the activity's groups has frozen_at; audits groups.activity_copied / activity_recopied / activity_detached. detach relies on the new FK cascade.
- pool(): assessment_settings.assign_to_specific_students=1 gives assignees intersected with the offering pool (so a dropped assignee is excluded); otherwise the offering pool. Learning-item configs use the offering pool.
- copyGroups() is protected so the atomicity test can inject a failure.

## Concerns
- GroupSchemaTest::testMigrationRollsBack drops and recreates student_groups / student_group_members, which would silently lose the new config FKs for later tests; I added a re-run of migration 5's up() in its finally block. Outside the listed files but necessary.
- The atomic test (and migration roll-back test) leave the isolation transaction to get real rollback/DDL behaviour; the atomic test deletes its committed offering afterwards.
- A set with exactly 50 groups is allowed; 51 refused. Set copies keep group names only (no leader/status).
