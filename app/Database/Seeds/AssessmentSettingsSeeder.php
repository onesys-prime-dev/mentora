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
                'assessmentId'            => $assessment['id'],
                'allowLateSubmission'     => false,
                'showResultsImmediately'  => true,
                'showCorrectAnswers'      => true,
                'allowReviewAfterSubmission' => true,
                'requirePassword'         => false,
                'password'                => null,
                'randomizeQuestions'      => true,
                'randomizeChoices'        => true,
                'browserLockdown'         => false,
                'allowMultipleAttempts'   => false,
                'createdAt'               => $now,
                'updatedAt'               => null,
            ];
        }

        $this->db->table('assessment_settings')->insertBatch($data);
    }
}