<?php

namespace App\Database\Seeds;

use CodeIgniter\Database\Seeder;

class AssessmentRubricsSeeder extends Seeder
{
    public function run()
    {
        if ($this->db->table('assessment_rubrics')->countAllResults() > 0) {
            return;
        }

        $now = date('Y-m-d H:i:s');

        $questions = $this->db->table('assessment_questions')
            ->select('id, questionText, questionType')
            ->whereIn('questionType', ['Essay', 'Short Answer'])
            ->get()
            ->getResultArray();

        if (empty($questions)) {
            return;
        }

        $instructorId = 1;

        $data = [];

        foreach ($questions as $question) {

            switch ($question['questionType']) {

                case 'Essay':
                    $title = 'Essay Assessment Rubric';
                    $description = 'Rubric used to evaluate essay responses.';
                    $totalPoints = 100;
                    break;

                case 'Short Answer':
                    $title = 'Short Answer Rubric';
                    $description = 'Rubric used to evaluate short answer responses.';
                    $totalPoints = 20;
                    break;

                default:
                    continue 2;
            }

            $data[] = [
                'questionId'   => $question['id'],
                'title'        => $title,
                'description'  => $description,
                'totalPoints'  => $totalPoints,
                'createdBy'    => $instructorId,
                'createdAt'    => $now,
                'updatedBy'    => null,
                'updatedAt'    => null,
                'status'       => 'Active',
            ];
        }

        if (!empty($data)) {
            $this->db->table('assessment_rubrics')->insertBatch($data);
        }
    }
}