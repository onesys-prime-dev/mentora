<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Replaces the single, overwritable assessment_submissions.feedback column
 * with a real thread: many messages per submission, from either the
 * grading instructor or the student, in the order they were posted.
 */
class CreateAssessmentFeedbacks extends Migration
{
    public function up()
    {
        $this->forge->addField([
            'id' => [
                'type'           => 'INT',
                'constraint'     => 20,
                'unsigned'       => true,
                'auto_increment' => true,
            ],

            'submission_id' => [
                'type'       => 'INT',
                'constraint' => 20,
                'unsigned'   => true,
            ],

            'author_id' => [
                'type'       => 'INT',
                'constraint' => 20,
                'unsigned'   => true,
            ],

            'author_role' => [
                'type'       => 'ENUM',
                'constraint' => ['instructor', 'student'],
            ],

            'message' => [
                'type' => 'TEXT',
            ],

            'created_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
        ]);

        $this->forge->addKey('id', true);
        $this->forge->addKey('submission_id');
        $this->forge->addKey(['submission_id', 'id']);

        $this->forge->createTable('assessment_feedbacks');

        $this->db->query("
            ALTER TABLE assessment_feedbacks
            ADD CONSTRAINT fk_feedback_submission
            FOREIGN KEY (submission_id)
            REFERENCES assessment_submissions(id)
            ON DELETE CASCADE
            ON UPDATE CASCADE
        ");

        $this->db->query("
            ALTER TABLE assessment_feedbacks
            ADD CONSTRAINT fk_feedback_author
            FOREIGN KEY (author_id)
            REFERENCES users(id)
            ON DELETE CASCADE
            ON UPDATE CASCADE
        ");

        // Carry every existing (non-empty) feedback comment over as that
        // submission's opening instructor message before the column goes away.
        $this->db->query("
            INSERT INTO assessment_feedbacks (submission_id, author_id, author_role, message, created_at)
            SELECT id, graded_by, 'instructor', feedback, COALESCE(graded_at, NOW())
            FROM assessment_submissions
            WHERE graded_by IS NOT NULL
              AND TRIM(COALESCE(feedback, '')) <> ''
        ");

        $this->forge->dropColumn('assessment_submissions', 'feedback');
    }

    public function down()
    {
        $this->forge->addColumn('assessment_submissions', [
            'feedback' => [
                'type' => 'TEXT',
                'null' => true,
                'after' => 'graded_at',
            ],
        ]);

        // Best-effort restore: the latest message per submission becomes
        // the column's value again (a thread can't losslessly fold back
        // into one field, so this only recovers what down() reasonably can).
        $this->db->query("
            UPDATE assessment_submissions s
            SET feedback = (
                SELECT f.message
                FROM assessment_feedbacks f
                WHERE f.submission_id = s.id
                ORDER BY f.id DESC
                LIMIT 1
            )
        ");

        $this->forge->dropTable('assessment_feedbacks', true);
    }
}
