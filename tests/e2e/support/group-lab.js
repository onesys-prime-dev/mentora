import { runSql } from "./database.js";

const lab = "(SELECT id FROM assessments WHERE title = 'Lab 1: Designing a Table' LIMIT 1)";

/**
 * Puts "Lab 1: Designing a Table" back to a plain assignment: no group hand-ins, no group activity.
 * The group specs share the lab and run in file order, so each one starts from this state.
 */
export function resetLab() {
    runSql(`DELETE FROM assessment_submissions WHERE assessment_id = ${lab} AND group_id IS NOT NULL`);
    runSql(`DELETE FROM activity_group_configs WHERE assessment_id = ${lab}`);
}
