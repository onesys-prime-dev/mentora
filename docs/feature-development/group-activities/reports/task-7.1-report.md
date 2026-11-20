# Task 7.1 report: rubric criterion scope

Status: DONE (uncommitted).

## Red phase
`Tests: 30, Assertions: 91, Errors: 2, Failures: 4` (RubricServiceTest + AddScopeToRubricCriteriaMigrationTest + RubricScopeRouteTest).

## Green
- PHPUnit `--filter 'Rubric|GradeWork|Assessment|AddScope'`: Tests: 901, Assertions: 2248, OK.
- `npx vitest run`: 119 files, 1214 tests passed.
- eslint on rubrics.js and the new test: clean. phpstan not run.

## Files
- app/Database/Migrations/2026-12-01-000009_AddScopeToAssessmentRubricCriteria.php (new, reversible)
- app/Models/Assessments/AssessmentRubricCriteriaModel.php (allowedFields)
- app/Services/Courses/Assessments/RubricService.php (create/select/createCriterion/updateCriterion read and write scope; invalid gives "Scope must be group or individual."; a refused create keeps the current rubric)
- app/Services/Courses/Assessments/AssessmentService.php (assessment duplicate keeps scope; editAssessment passes isGroupActivity)
- app/Views/pages/courses/features/assessments/assessment-types/editor/assignment.php (data-group-activity on the rubric container)
- app/Views/.../partials/modals/rubric-criteria-create.php and rubric-criteria-editor.php (Scored select via components/form-field, hidden unless group activity, with the helper text)
- resources/js/pages/courses/features/assessments/rubrics/rubrics.js (scope always sent; select shown for group activities; card shows "Scored: ..." for group activities)
- Tests: tests/database/RubricServiceTest.php (4 added), tests/database/AddScopeToRubricCriteriaMigrationTest.php, tests/feature/RubricScopeRouteTest.php, tests/js/pages/rubric-scope.test.js

## Notes
- Criteria are returned by findAll, so every endpoint and the grading read path include scope automatically.
- ContentTransferService and OfferingCopyService clone rows with all columns, so scope is preserved there with no change.
- The editor UI is card and modal based (no table), so "Scored" is a select in the add and edit criterion modals.
- Scores are not computed differently (7.2/7.3).
