<?php

namespace App\Database\Seeds;

use CodeIgniter\Database\Seeder;

class GradebookRecordsSeeder extends Seeder
{
    public function run()
    {
        if ($this->db->table('gradebook_records')->countAllResults() > 0) {
            return;
        }

        $now = date('Y-m-d H:i:s');

        $courses = $this->db->table('courses')
            ->select('id')
            ->orderBy('id')
            ->get()
            ->getResultArray();

        if (empty($courses)) {
            return;
        }

        // Demo student created by DemoUsersSeeder
        $studentId = 2;

        foreach ($courses as $course) {

            // Retrieve graded submissions for this student in this course
            $submissions = $this->db->table('assessment_submissions s')
                ->select('
                    s.score,
                    a.totalPoints
                ')
                ->join('assessments a', 'a.id = s.assessmentId')
                ->where('a.courseId', $course['id'])
                ->where('s.studentId', $studentId)
                ->where('s.status', 'Graded')
                ->get()
                ->getResultArray();

            $earnedPoints = 0;
            $possiblePoints = 0;

            foreach ($submissions as $submission) {

                $earnedPoints += (float) $submission['score'];
                $possiblePoints += (float) $submission['totalPoints'];
            }

            $percentage = 0;

            if ($possiblePoints > 0) {
                $percentage = round(($earnedPoints / $possiblePoints) * 100, 2);
            }

            $finalGrade = $percentage;

            $remarks = $percentage >= 75
                ? 'Passed'
                : 'Failed';

            $this->db->table('gradebook_records')->insert([

                'courseId' => $course['id'],

                'studentId' => $studentId,

                'calculatedFinalGrade' => $finalGrade,

                'overriddenFinalGrade' => null,

                'isOverrideFinalGrade' => 0,

                'overallPercentage' => $percentage,

                'remarks' => $remarks,

                'instructorRemarks' => 'Initial grade generated from seeded assessment submissions.',

                'overrideReason' => null,

                'lastCalculatedAt' => $now,

                'updatedBy' => 1,

                'status' => 'Active',

                'createdAt' => $now,

                'updatedAt' => null,
            ]);
        }
    }
}