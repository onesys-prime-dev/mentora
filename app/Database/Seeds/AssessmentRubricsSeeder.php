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

        $instructorId = 1;

        $data = [];

        /*
        |--------------------------------------------------------------------------
        | Assignment-linked Rubric
        |--------------------------------------------------------------------------
        */

        $assignment = $this->db->table('assessments')
            ->select('id')
            ->where('title', 'Module Assignment')
            ->get()
            ->getRowArray();

        if ($assignment) {

            $data[] = [
                'question_id'   => null,
                'assessment_id' => $assignment['id'],
                'title'         => 'Programming Assignment Rubric',
                'description'   => 'Rubric used to evaluate the module programming assignment.',
                'total_points'  => 100,
                'created_by'    => $instructorId,
                'created_at'    => $now,
                'updated_by'    => null,
                'updated_at'    => null,
                'status'        => 'Active',
            ];
        }

        /*
        |--------------------------------------------------------------------------
        | Question-linked Rubrics (Quiz)
        |--------------------------------------------------------------------------
        */

        $questions = $this->db->table('assessment_questions')
            ->select('id, question_text, question_type')
            ->whereIn('question_type', ['Essay', 'Short Answer'])
            ->get()
            ->getResultArray();

        foreach ($questions as $question) {

            switch ($question['question_type']) {

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
                'question_id'   => $question['id'],
                'assessment_id' => null,
                'title'         => $title,
                'description'   => $description,
                'total_points'  => $totalPoints,
                'created_by'    => $instructorId,
                'created_at'    => $now,
                'updated_by'    => null,
                'updated_at'    => null,
                'status'        => 'Active',
            ];
        }

        if (!empty($data)) {
            $this->db->table('assessment_rubrics')->insertBatch($data);
        }
    }
}
