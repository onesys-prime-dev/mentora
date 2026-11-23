import { runSql } from "./database.js";

const lab = "(SELECT id FROM assessments WHERE title = 'Lab 1: Designing a Table' LIMIT 1)";

/**
 * Puts "Lab 1: Designing a Table" back to a plain assignment: no group hand-ins, no group activity.
 * The group specs share the lab and run in file order, so each one starts from this state.
 */
export function resetLab() {
    // The lab sits behind "What Is a Database?": let the four students in.
    for (const n of [1, 2, 3, 4]) {
        runSql(`INSERT INTO learning_completions (learning_item_id, student_user_id, status, completed_at, created_at)
            SELECT i.id, u.user_id, 'completed', NOW(), NOW() FROM learning_items i JOIN auth_identities u ON u.secret = 'student${n}@mentora.test'
            WHERE i.title = 'What Is a Database?' AND NOT EXISTS (SELECT 1 FROM learning_completions c WHERE c.learning_item_id = i.id AND c.student_user_id = u.user_id AND c.status = 'completed')`);
    }

    runSql(`DELETE FROM assessment_submissions WHERE assessment_id = ${lab} AND group_id IS NOT NULL`);
    runSql(`DELETE FROM activity_group_configs WHERE assessment_id = ${lab}`);
}

const offering = "(SELECT o.id FROM course_offerings o JOIN courses c ON c.id = o.course_id WHERE c.course_code = 'IT101' AND o.section_code = 'A' LIMIT 1)";

/** Makes the lab a group activity by SQL; `values` maps column to a SQL literal (e.g. { formation_mode: "'proposed'" }). */
export function createLabConfig(values = {}) {
    const row = { offering_id: offering, assessment_id: lab, created_at: "NOW()", ...values };

    runSql(`INSERT INTO activity_group_configs (${Object.keys(row).join(", ")}) VALUES (${Object.values(row).join(", ")})`);
}
