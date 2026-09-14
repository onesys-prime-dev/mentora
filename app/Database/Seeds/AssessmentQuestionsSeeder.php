<?php

namespace App\Database\Seeds;

use CodeIgniter\Database\Seeder;

class AssessmentQuestionsSeeder extends Seeder
{
    public function run()
    {
        if ($this->db->table('assessment_questions')->countAllResults() > 0) {
            return;
        }

        $now = date('Y-m-d H:i:s');

        $assessments = $this->db->table('assessments')
            ->select('id, title')
            ->orderBy('id')
            ->get()
            ->getResultArray();

        if (empty($assessments)) {
            return;
        }

        $data = [];

        foreach ($assessments as $assessment) {

            switch ($assessment['title']) {

                case 'Course Pre-Test':

                    $data[] = [
                        'assessment_id' => $assessment['id'],
                        'question_text' => 'What does CPU stand for?',
                        'question_type' => 'Multiple Choice',
                        'points' => 5,
                        'is_required' => 1,
                        'order_index' => 1,
                        'created_by' => 1,
                        'created_at' => $now,
                        'updated_by' => null,
                        'updated_at' => null,
                        'status' => 'Active',
                    ];

                    $data[] = [
                        'assessment_id' => $assessment['id'],
                        'question_text' => 'Which device is considered an output device?',
                        'question_type' => 'Multiple Choice',
                        'points' => 5,
                        'is_required' => 1,
                        'order_index' => 2,
                        'created_by' => 1,
                        'created_at' => $now,
                        'updated_by' => null,
                        'updated_at' => null,
                        'status' => 'Active',
                    ];

                    $data[] = [
                        'assessment_id' => $assessment['id'],
                        'question_text' => 'Explain why computers are important in education.',
                        'question_type' => 'Essay',
                        'points' => 10,
                        'is_required' => 1,
                        'order_index' => 3,
                        'created_by' => 1,
                        'created_at' => $now,
                        'updated_by' => null,
                        'updated_at' => null,
                        'status' => 'Active',
                    ];

                    break;

                case 'Module Assignment':

                    $data[] = [
                        'assessment_id' => $assessment['id'],
                        'question_text' => 'Upload your completed programming activity.',
                        'question_type' => 'File Upload',
                        'points' => 80,
                        'is_required' => 1,
                        'order_index' => 1,
                        'created_by' => 1,
                        'created_at' => $now,
                        'updated_by' => null,
                        'updated_at' => null,
                        'status' => 'Active',
                    ];

                    $data[] = [
                        'assessment_id' => $assessment['id'],
                        'question_text' => 'Briefly explain the algorithm used in your solution.',
                        'question_type' => 'Short Answer',
                        'points' => 20,
                        'is_required' => 1,
                        'order_index' => 2,
                        'created_by' => 1,
                        'created_at' => $now,
                        'updated_by' => null,
                        'updated_at' => null,
                        'status' => 'Active',
                    ];

                    break;

                case 'Reflection Essay':

                    $data[] = [
                        'assessment_id' => $assessment['id'],
                        'question_text' => 'Write a reflection about what you learned from this lesson.',
                        'question_type' => 'Essay',
                        'points' => 50,
                        'is_required' => 1,
                        'order_index' => 1,
                        'created_by' => 1,
                        'created_at' => $now,
                        'updated_by' => null,
                        'updated_at' => null,
                        'status' => 'Active',
                    ];

                    break;
            }
        }

        if (!empty($data)) {
            $this->db->table('assessment_questions')->insertBatch($data);
        }
    }
}
