<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AlterAssessmentSubmissionsAddRubricScores extends Migration
{
    public function up()
    {
        $this->forge->addColumn('assessment_submissions', [
            'grading_mode' => [
                'type'       => 'ENUM',
                'constraint' => ['Default', 'Rubric'],
                'null'       => true,
                'after'      => 'feedback',
            ],

            'rubric_scores' => [
                'type'    => 'JSON',
                'null'    => true,
                'after'   => 'grading_mode',
            ],
        ]);
    }

    public function down()
    {
        $this->forge->dropColumn('assessment_submissions', ['grading_mode', 'rubric_scores']);
    }
}
