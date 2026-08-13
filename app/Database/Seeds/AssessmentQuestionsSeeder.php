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
                        'assessmentId' => $assessment['id'],
                        'questionText' => 'What does CPU stand for?',
                        'questionType' => 'Multiple Choice',
                        'points' => 5,
                        'isRequired' => 1,
                        'orderIndex' => 1,
                        'createdBy' => 1,
                        'createdAt' => $now,
                        'updatedBy' => null,
                        'updatedAt' => null,
                        'status' => 'Active',
                    ];

                    $data[] = [
                        'assessmentId' => $assessment['id'],
                        'questionText' => 'Which device is considered an output device?',
                        'questionType' => 'Multiple Choice',
                        'points' => 5,
                        'isRequired' => 1,
                        'orderIndex' => 2,
                        'createdBy' => 1,
                        'createdAt' => $now,
                        'updatedBy' => null,
                        'updatedAt' => null,
                        'status' => 'Active',
                    ];

                    $data[] = [
                        'assessmentId' => $assessment['id'],
                        'questionText' => 'Explain why computers are important in education.',
                        'questionType' => 'Essay',
                        'points' => 10,
                        'isRequired' => 1,
                        'orderIndex' => 3,
                        'createdBy' => 1,
                        'createdAt' => $now,
                        'updatedBy' => null,
                        'updatedAt' => null,
                        'status' => 'Active',
                    ];

                    break;

                case 'Module Assignment':

                    $data[] = [
                        'assessmentId' => $assessment['id'],
                        'questionText' => 'Upload your completed programming activity.',
                        'questionType' => 'File Upload',
                        'points' => 80,
                        'isRequired' => 1,
                        'orderIndex' => 1,
                        'createdBy' => 1,
                        'createdAt' => $now,
                        'updatedBy' => null,
                        'updatedAt' => null,
                        'status' => 'Active',
                    ];

                    $data[] = [
                        'assessmentId' => $assessment['id'],
                        'questionText' => 'Briefly explain the algorithm used in your solution.',
                        'questionType' => 'Short Answer',
                        'points' => 20,
                        'isRequired' => 1,
                        'orderIndex' => 2,
                        'createdBy' => 1,
                        'createdAt' => $now,
                        'updatedBy' => null,
                        'updatedAt' => null,
                        'status' => 'Active',
                    ];

                    break;

                case 'Reflection Essay':

                    $data[] = [
                        'assessmentId' => $assessment['id'],
                        'questionText' => 'Write a reflection about what you learned from this lesson.',
                        'questionType' => 'Essay',
                        'points' => 50,
                        'isRequired' => 1,
                        'orderIndex' => 1,
                        'createdBy' => 1,
                        'createdAt' => $now,
                        'updatedBy' => null,
                        'updatedAt' => null,
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