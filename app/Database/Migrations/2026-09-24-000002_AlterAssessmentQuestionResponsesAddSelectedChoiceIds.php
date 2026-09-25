<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AlterAssessmentQuestionResponsesAddSelectedChoiceIds extends Migration
{
    public function up()
    {
        $this->forge->addColumn('assessment_question_responses', [

            'selected_choice_ids' => [
                'type'    => 'JSON',
                'null'    => true,
                'after'   => 'selected_choice_id',
                'comment' => 'Multi-answer Multiple Choice only — array of selected choice ids, in place of the single selected_choice_id column',
            ],

        ]);
    }

    public function down()
    {
        $this->forge->dropColumn('assessment_question_responses', 'selected_choice_ids');
    }
}
