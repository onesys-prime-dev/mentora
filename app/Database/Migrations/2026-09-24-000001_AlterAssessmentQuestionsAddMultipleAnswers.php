<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AlterAssessmentQuestionsAddMultipleAnswers extends Migration
{
    public function up()
    {
        $this->forge->addColumn('assessment_questions', [

            'allow_multiple_answers' => [
                'type'       => 'BOOLEAN',
                'default'    => false,
                'after'      => 'question_type',
                'comment'    => 'Multiple Choice only — lets students check more than one choice',
            ],

            'answers_count' => [
                'type'       => 'INT',
                'constraint' => 11,
                'unsigned'   => true,
                'null'       => true,
                'after'      => 'allow_multiple_answers',
                'comment'    => 'How many choices are correct / the student must select — only meaningful when allow_multiple_answers is true',
            ],

        ]);
    }

    public function down()
    {
        $this->forge->dropColumn('assessment_questions', 'allow_multiple_answers');
        $this->forge->dropColumn('assessment_questions', 'answers_count');
    }
}
