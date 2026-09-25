<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Short Answer auto-grading:
 *  - accepted_answers (JSON list, first = main answer) and case_sensitive
 *    (default off) on questions and on AI-generated questions
 *  - a "Partially Graded" submission status: a quiz whose auto-gradable
 *    questions are scored but whose essays / uploads still need a person
 */
class AddShortAnswerKeysAndPartiallyGraded extends Migration
{
    private const QUESTION_TABLES = ['assessment_questions', 'ai_generated_questions'];

    public function up()
    {
        foreach (self::QUESTION_TABLES as $table) {
            $this->forge->addColumn($table, [
                'accepted_answers' => [
                    'type' => 'LONGTEXT',
                    'null' => true,
                    'after' => 'answers_count',
                ],
                'case_sensitive' => [
                    'type'       => 'TINYINT',
                    'constraint' => 1,
                    'default'    => 0,
                    'null'       => false,
                    'after'      => 'accepted_answers',
                ],
            ]);
        }

        $this->setStatuses(['In Progress', 'Submitted', 'Partially Graded', 'Grading', 'Graded', 'Returned', 'Cancelled']);
    }

    public function down()
    {
        // The auto part was scored but a person never finished it.
        $this->db->table('assessment_submissions')->where('status', 'Partially Graded')->update(['status' => 'Submitted']);

        $this->setStatuses(['In Progress', 'Submitted', 'Grading', 'Graded', 'Returned', 'Cancelled']);

        foreach (self::QUESTION_TABLES as $table) {
            $this->forge->dropColumn($table, ['accepted_answers', 'case_sensitive']);
        }
    }

    private function setStatuses(array $statuses): void
    {
        $this->forge->modifyColumn('assessment_submissions', [
            'status' => [
                'name'       => 'status',
                'type'       => 'ENUM',
                'constraint' => $statuses,
                'default'    => 'In Progress',
            ],
        ]);
    }
}
