<?php

namespace App\Database\Seeds;

use CodeIgniter\Database\Seeder;

class AssessmentQuestionChoicesSeeder extends Seeder
{
    public function run()
    {
        if ($this->db->table('assessment_question_choices')->countAllResults() > 0) {
            return;
        }

        $now = date('Y-m-d H:i:s');

        $questions = $this->db->table('assessment_questions')
            ->select('id, question_text')
            ->where('question_type', 'Multiple Choice')
            ->get()
            ->getResultArray();

        if (empty($questions)) {
            return;
        }

        $data = [];

        foreach ($questions as $question) {

            switch ($question['question_text']) {

                case 'What does CPU stand for?':

                    $choices = [
                        ['Central Processing Unit', true],
                        ['Computer Processing Unit', false],
                        ['Central Program Utility', false],
                        ['Control Processing Utility', false],
                    ];

                    break;

                case 'Which device is considered an output device?':

                    $choices = [
                        ['Monitor', true],
                        ['Keyboard', false],
                        ['Mouse', false],
                        ['Scanner', false],
                    ];

                    break;

                default:
                    $choices = [];
                    break;
            }

            $order = 1;

            foreach ($choices as $choice) {

                $data[] = [
                    'question_id' => $question['id'],
                    'choice_text' => $choice[0],
                    'is_correct' => $choice[1],
                    'order_index' => $order++,
                    'created_at' => $now,
                    'updated_at' => null,
                ];
            }
        }

        if (!empty($data)) {
            $this->db->table('assessment_question_choices')->insertBatch($data);
        }
    }
}
