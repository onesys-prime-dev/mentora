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

const offering = "(SELECT o.id FROM course_offerings o JOIN courses c ON c.id = o.course_id WHERE c.course_code = 'IT101' AND o.section_code = 'A' LIMIT 1)";

/** Makes the lab a group activity by SQL; `values` maps column to a SQL literal (e.g. { formation_mode: "'proposed'" }). */
export function createLabConfig(values = {}) {
    const row = { offering_id: offering, assessment_id: lab, created_at: "NOW()", ...values };

    runSql(`INSERT INTO activity_group_configs (${Object.keys(row).join(", ")}) VALUES (${Object.values(row).join(", ")})`);
}
