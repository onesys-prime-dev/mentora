<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AlterAiGeneratedQuestionsAddMultipleAnswers extends Migration
{
    public function up()
    {
        $this->forge->addColumn('ai_generated_questions', [

            'allow_multiple_answers' => [
                'type'    => 'BOOLEAN',
                'default' => false,
                'after'   => 'is_required',
                'comment' => 'Multiple Choice only — mirrors assessment_questions.allow_multiple_answers',
            ],

            'answers_count' => [
                'type'       => 'INT',
                'constraint' => 11,
                'unsigned'   => true,
                'null'       => true,
                'after'      => 'allow_multiple_answers',
                'comment'    => 'Only meaningful when allow_multiple_answers is true',
            ],

        ]);
    }

    public function down()
    {
        $this->forge->dropColumn('ai_generated_questions', 'allow_multiple_answers');
        $this->forge->dropColumn('ai_generated_questions', 'answers_count');
    }
}
