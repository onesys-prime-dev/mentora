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
                    a.total_points
                ')
                ->join('assessments a', 'a.id = s.assessment_id')
                ->where('a.course_id', $course['id'])
                ->where('s.student_id', $studentId)
                ->where('s.status', 'Graded')
                ->get()
                ->getResultArray();

            $earnedPoints = 0;
            $possiblePoints = 0;

            foreach ($submissions as $submission) {

                $earnedPoints += (float) $submission['score'];
                $possiblePoints += (float) $submission['total_points'];
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

                'course_id' => $course['id'],

                'student_id' => $studentId,

                'calculated_final_grade' => $finalGrade,

                'overridden_final_grade' => null,

                'is_override_final_grade' => 0,

                'overall_percentage' => $percentage,

                'remarks' => $remarks,

                'instructor_remarks' => 'Initial grade generated from seeded assessment submissions.',

                'override_reason' => null,

                'last_calculated_at' => $now,

                'updated_by' => 1,

                'status' => 'Active',

                'created_at' => $now,

                'updated_at' => null,
            ]);
        }
    }
}
