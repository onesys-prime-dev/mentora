<?php

namespace App\Database\Seeds;

use CodeIgniter\Database\Seeder;

class AssessmentRubricCriteriaSeeder extends Seeder
{
    public function run()
    {
        if ($this->db->table('assessment_rubric_criteria')->countAllResults() > 0) {
            return;
        }

        $now = date('Y-m-d H:i:s');

        $rubrics = $this->db->table('assessment_rubrics')
            ->select('id, title')
            ->get()
            ->getResultArray();

        if (empty($rubrics)) {
            return;
        }

        $data = [];

        foreach ($rubrics as $rubric) {

            switch ($rubric['title']) {

                case 'Programming Assignment Rubric':

                    $criteria = [
                        [
                            'criteriaTitle' => 'Logic & Correctness',
                            'description'   => 'Recursion is correctly implemented and produces accurate results.',
                            'maxPoints'     => 50,
                        ],
                        [
                            'criteriaTitle' => 'Efficiency',
                            'description'   => 'Code is optimized and avoids unnecessary operations.',
                            'maxPoints'     => 30,
                        ],
                        [
                            'criteriaTitle' => 'Documentation',
                            'description'   => 'Clear docstrings, comments, and adherence to PEP 8 standards.',
                            'maxPoints'     => 20,
                        ],
                    ];

                    break;

                case 'Essay Assessment Rubric':

                    $criteria = [
                        [
                            'criteriaTitle' => 'Content Accuracy',
                            'description'   => 'Demonstrates correct understanding of the topic.',
                            'maxPoints'     => 40,
                        ],
                        [
                            'criteriaTitle' => 'Organization',
                            'description'   => 'Ideas are logically organized and easy to follow.',
                            'maxPoints'     => 20,
                        ],
                        [
                            'criteriaTitle' => 'Critical Thinking',
                            'description'   => 'Shows analysis, reasoning, and original thought.',
                            'maxPoints'     => 20,
                        ],
                        [
                            'criteriaTitle' => 'Grammar and Mechanics',
                            'description'   => 'Uses proper grammar, spelling, and sentence structure.',
                            'maxPoints'     => 20,
                        ],
                    ];

                    break;

                case 'Short Answer Rubric':

                    $criteria = [
                        [
                            'criteriaTitle' => 'Correctness',
                            'description'   => 'Answer correctly addresses the question.',
                            'maxPoints'     => 10,
                        ],
                        [
                            'criteriaTitle' => 'Completeness',
                            'description'   => 'Response is complete and sufficiently detailed.',
                            'maxPoints'     => 5,
                        ],
                        [
                            'criteriaTitle' => 'Clarity',
                            'description'   => 'Response is clear and understandable.',
                            'maxPoints'     => 5,
                        ],
                    ];

                    break;

                default:
                    $criteria = [];
                    break;
            }

            $order = 1;

            foreach ($criteria as $criterion) {

                $data[] = [
                    'rubric_id'      => $rubric['id'],
                    'criteria_title' => $criterion['criteriaTitle'],
                    'description'    => $criterion['description'],
                    'max_points'     => $criterion['maxPoints'],
                    'order_index'    => $order++,
                    'created_at'     => $now,
                    'updated_at'     => null,
                ];
            }
        }

        if (!empty($data)) {
            $this->db->table('assessment_rubric_criteria')->insertBatch($data);
        }
    }
}
