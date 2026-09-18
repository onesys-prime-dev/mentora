<?php

namespace App\Database\Seeds;

use CodeIgniter\Database\Seeder;

class AssessmentSettingsSeeder extends Seeder
{
    public function run()
    {
        if ($this->db->table('assessment_settings')->countAllResults() > 0) {
            return;
        }

        $now = date('Y-m-d H:i:s');

        $assessments = $this->db->table('assessments')
            ->select('id')
            ->get()
            ->getResultArray();

        if (empty($assessments)) {
            return;
        }

        $data = [];

        foreach ($assessments as $assessment) {
            $data[] = [
                'assessment_id'                 => $assessment['id'],
                'allow_late_submission'         => false,
                'show_results_immediately'      => true,
                'show_correct_answers'          => true,
                'allow_review_after_submission' => true,
                'require_password'              => false,
                'password'                      => null,
                'randomize_questions'           => true,
                'randomize_choices'             => true,
                'browser_lockdown'               => false,
                'allow_multiple_attempts'       => false,
                'created_at'                    => $now,
                'updated_at'                    => null,
            ];
        }

        $this->db->table('assessment_settings')->insertBatch($data);
    }
}
