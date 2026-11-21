# Tasks 9.3 and 9.4 report

## 9.3 Exports / import

Finding: there is NO per-assessment grade sheet export. GradeSheetExportService is the course-level (offering) offline sheet; GradeReportService is the course gradebook report; QuestionExportService exports questions. None has a per-assessment group meaning, so per the brief they stay byte-for-byte unchanged and no Group column was added. Adding one to the grade sheet would also break its import (the import refuses any added/reordered column via the layout key check).

Import today: GradeSheetImportService::apply() only writes MANUAL grade items through GradebookSyncService::setManualScore (gradebook_item_scores). Items tied to an assessment (including a group activity) are not manual, so they are skipped. It never writes assessment_submissions.score or group_member_scores, so no refusal message ('This is a group activity...') was needed and none was added. A sheet with an extra Group column is refused at preview ("Columns were added...") like any added column, and writes nothing; nothing exports such a column.

Tests (tests/database/GroupGradeSheetTest.php, characterization, green from the start so no red phase):
- sheet of an offering with a group activity has no Group column
- extra Group column is refused, no writes
- apply with a manual item + a group-activity item imports only the manual one; group_member_scores and assessment_submissions untouched.

## 9.4 Offering copy

OfferingCopyService::copyOne now calls copyGroupSets() inside the copier's existing transaction. Copies active group_sets only (name, formation_mode, min/max size, signup_opens_at / signup_closes_at shifted by the term shift days like assessment dates, status active, created_by NULL) and their active student_groups as empty rows (set_id + name). Never members, leader, config_id, activity_group_configs, group_member_scores, invitations, votes, extensions. Archived sets not copied. 20-sets/50-groups limits hold because the source already satisfies them (archived are excluded from the count); no extra check added. ContentTransferService (course-to-course) not touched.

Rubric criteria `scope`: confirmed it survives (cloneRows copies all columns); tested.

Tests added to tests/database/OfferingCopyServiceTest.php: testCopyHasSetsWithEmptyGroupsAndNoMembers, testCopiedAssessmentsHaveNoActivityGroupConfigAndRubricScopeSurvives, testAnOfferingWithoutSetsCopiesAsBefore.
Red phase: `1) OfferingCopyServiceTest::testCopyHasSetsWithEmptyGroupsAndNoMembers  archived set is not copied. Failed asserting that actual size 0 matches expected size 1.` (Tests: 19, Failures: 1). The other two passed pre-implementation by design (nothing copied them).

## Files
- app/Services/Institutions/OfferingCopyService.php
- tests/database/OfferingCopyServiceTest.php
- tests/database/GroupGradeSheetTest.php
