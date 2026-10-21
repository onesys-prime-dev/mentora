<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * One row per attempt number for a student on an assessment, so two submits
 * sent at the same moment can no longer both pass the attempt limit.
 */
class AddUniqueSubmissionAttempt extends Migration
{
    public function up()
    {
        $t = db_connect()->prefixTable('assessment_submissions');

        // Close any gaps or repeats the old check-then-insert left, or the index cannot be built.
        $this->db->query(
            "UPDATE {$t} s JOIN (
                SELECT id, ROW_NUMBER() OVER (PARTITION BY assessment_id, student_id ORDER BY attempt_number, id) AS rn
                FROM {$t}
            ) r ON r.id = s.id SET s.attempt_number = r.rn"
        );

        $this->db->query("ALTER TABLE {$t} ADD UNIQUE KEY uq_submission_attempt (assessment_id, student_id, attempt_number)");
    }

    public function down()
    {
        $this->db->query('ALTER TABLE ' . db_connect()->prefixTable('assessment_submissions') . ' DROP INDEX uq_submission_attempt');
    }
}
