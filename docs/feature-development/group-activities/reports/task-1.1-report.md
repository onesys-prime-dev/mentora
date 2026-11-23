# Task 1.1 report: group schema and models

Status: DONE

Files created
- app/Database/Migrations/2026-12-01-000001_CreateGroupSets.php
- app/Database/Migrations/2026-12-01-000002_CreateStudentGroups.php
- app/Database/Migrations/2026-12-01-000003_CreateStudentGroupMembers.php
- app/Models/Groups/GroupSetModel.php, StudentGroupModel.php, StudentGroupMemberModel.php
- app/Services/Groups/GroupLimits.php
- tests/database/GroupSchemaTest.php

Tests (scratch MariaDB, port 3307)
- Red first: failed (tables missing). After implementation: GroupSchemaTest 4 tests, 11 assertions, OK.
- AssessmentAssigneesTest: 15 tests, 30 assertions, OK.

Notes
- config_id on student_groups and student_group_members has no FK (Phase 3 adds it); commented in both migrations.
- Unique keys are named uq_group_member_set / uq_group_member_config.
- Inside the isolated-test transaction CodeIgniter does not throw on a failed insert, so the duplicate test asserts error code 1062 and an unchanged row count instead of expectException.
- Test method names use the existing camelCase style (testStudentCannotBeInTwoGroupsOfOneSet), not snake_case.
- The rollback test commits DDL, so it ends the isolation transaction, runs down() (children first), then recreates the tables in a finally block and reopens a transaction.
- group_sets.max_size defaults to 30, min_size to 1; created_by and leader/proposed_by FKs are SET NULL on user delete; member student FK cascades.
- Nothing committed.
