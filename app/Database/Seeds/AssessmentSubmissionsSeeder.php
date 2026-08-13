<?php

namespace App\Database\Seeds;

use CodeIgniter\Database\Seeder;

class AssessmentSubmissionsSeeder extends Seeder
{
    public function run()
    {
        if ($this->db->table('assessment_submissions')->countAllResults() > 0) {
            return;
        }

        $now = date('Y-m-d H:i:s');

        // -----------------------------------------------------------------
        // Demo instructor
        // -----------------------------------------------------------------

        $instructor = $this->db->table('users')
            ->select('id')
            ->orderBy('id', 'ASC')
            ->get()
            ->getFirstRow('array');

        if (!$instructor) {
            return;
        }

        $instructorId = $instructor['id'];

        // -----------------------------------------------------------------
        // Demo student
        // -----------------------------------------------------------------

        $students = $this->db->table('users')
            ->select('id')
            ->orderBy('id', 'ASC')
            ->get()
            ->getResultArray();

        if (count($students) < 2) {
            return;
        }

        $studentId = $students[1]['id'];

        // -----------------------------------------------------------------
        // Assessments
        // -----------------------------------------------------------------

        $assessments = $this->db->table('assessments')
            ->orderBy('id')
            ->get()
            ->getResultArray();

        if (empty($assessments)) {
            return;
        }

        $rows = [];

        foreach ($assessments as $assessment) {

            $startedAt = date(
                'Y-m-d H:i:s',
                strtotime('-2 hours')
            );

            $submittedAt = null;

            $score = null;

            $percentage = null;

            $feedback = null;

            $gradedBy = null;

            $gradedAt = null;

            $isLateSubmission = 0;

            $isGraded = 0;

            $timeSpent = null;

            $status = 'In Progress';

            switch ($assessment['title']) {

                case 'Course Pre-Test':

                    $score = 42;

                    $percentage = round(
                        ($score / $assessment['totalPoints']) * 100,
                        2
                    );

                    $submittedAt = date(
                        'Y-m-d H:i:s',
                        strtotime('-90 minutes')
                    );

                    $gradedAt = date(
                        'Y-m-d H:i:s',
                        strtotime('-60 minutes')
                    );

                    $feedback = 'Good work! Review hardware concepts to improve your score.';

                    $gradedBy = $instructorId;

                    $isGraded = 1;

                    $timeSpent = 1800;

                    $status = 'Graded';

                    break;

                case 'Module Assignment':

                    $submittedAt = date(
                        'Y-m-d H:i:s',
                        strtotime('-30 minutes')
                    );

                    $timeSpent = 2400;

                    $status = 'Submitted';

                    break;

                case 'Reflection Essay':

                    $status = 'In Progress';

                    break;

                default:

                    $status = 'Submitted';
            }

            $rows[] = [

                'assessmentId' => $assessment['id'],

                'studentId' => $studentId,

                'attemptNumber' => 1,

                'startedAt' => $startedAt,

                'submittedAt' => $submittedAt,

                'timeSpent' => $timeSpent,

                'score' => $score,

                'percentage' => $percentage,

                'isLateSubmission' => $isLateSubmission,

                'isGraded' => $isGraded,

                'gradedBy' => $gradedBy,

                'gradedAt' => $gradedAt,

                'feedback' => $feedback,

                'status' => $status
            ];
        }

        $this->db
            ->table('assessment_submissions')
            ->insertBatch($rows);
    }
}