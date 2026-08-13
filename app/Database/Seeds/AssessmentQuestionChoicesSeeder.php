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
            ->select('id, questionText')
            ->where('questionType', 'Multiple Choice')
            ->get()
            ->getResultArray();

        if (empty($questions)) {
            return;
        }

        $data = [];

        foreach ($questions as $question) {

            switch ($question['questionText']) {

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
                    'questionId' => $question['id'],
                    'choiceText' => $choice[0],
                    'isCorrect' => $choice[1],
                    'orderIndex' => $order++,
                    'createdAt' => $now,
                    'updatedAt' => null,
                ];
            }
        }

        if (!empty($data)) {
            $this->db->table('assessment_question_choices')->insertBatch($data);
        }
    }
}