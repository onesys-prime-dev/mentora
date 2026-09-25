<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AlterAiGeneratedQuestionsAddMathCheck extends Migration
{
    public function up()
    {
        $this->forge->addColumn('ai_generated_questions', [

            'math_check' => [
                'type'       => 'ENUM',
                'constraint' => ['verified', 'mismatch', 'not_applicable'],
                'default'    => 'not_applicable',
                'after'      => 'similarity_score',
                'comment'    => 'Deterministic sympy cross-check of the marked-correct MCQ answer — never trust the model\'s own claim',
            ],

        ]);
    }

    public function down()
    {
        $this->forge->dropColumn('ai_generated_questions', 'math_check');
    }
}
