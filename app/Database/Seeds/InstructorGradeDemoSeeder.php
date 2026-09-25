<?php

namespace App\Database\Seeds;

use CodeIgniter\Database\Seeder;

/**
 * Fleshes out the demo instructor's (instructor.demo1 / Maria Santos)
 * section with enough variety to actually exercise the grades page.
 *
 * Everything up to this seeder was built around a single demo student
 * (student_id 2, hardcoded in AssessmentSubmissionsSeeder and
 * GradebookRecordsSeeder) — fine for the student-facing grade view, but
 * IT101-A (offering_id 1) only had 2 of its enrolled students with any
 * data at all, and the other was completely blank. An instructor's
 * gradebook with one real row isn't a usable preview of that screen.
 *
 * This adds two more students to the roster and gives the section a
 * realistic spread across every state the UI renders differently:
 * a strong Passed student, a weak Failed one, and an Incomplete one
 * who hasn't submitted anything yet — alongside the existing student's
 * partially-graded work, which is left untouched.
 */
class InstructorGradeDemoSeeder extends Seeder
{
    public function run()
    {
        $offeringId = 1; // IT101-A, taught by instructor.demo1
        $courseId   = 1;
        $gradedBy   = 6; // instructor.demo1's user id

        $now = date('Y-m-d H:i:s');

        // -----------------------------------------------------------------
        // Roster: add two idle demo students to IT101-A, alongside the
        // student_id 3 who was already enrolled but had no submissions.
        // -----------------------------------------------------------------

        $newEnrollees = [1, 4];

        foreach ($newEnrollees as $studentId) {

            $exists = $this->db->table('enrollments')
                ->where('offering_id', $offeringId)
                ->where('student_user_id', $studentId)
                ->countAllResults() > 0;

            if ($exists) {
                continue;
            }

            $this->db->table('enrollments')->insert([
                'offering_id'     => $offeringId,
                'student_user_id' => $studentId,
                'status'          => 'enrolled',
                'enrolled_at'     => $now,
                'updated_at'      => $now,
            ]);
        }

        // -----------------------------------------------------------------
        // Assessments for this course, keyed by title so the scores below
        // read clearly against what each one actually is.
        // -----------------------------------------------------------------

        $assessments = $this->db->table('assessments')
            ->where('course_id', $courseId)
            ->get()
            ->getResultArray();

        $byTitle = [];

        foreach ($assessments as $assessment) {
            $byTitle[$assessment['title']] = $assessment;
        }

        if (! isset($byTitle['Course Pre-Test'], $byTitle['Module Assignment'], $byTitle['Reflection Journal'])) {
            return;
        }

        $preTest      = $byTitle['Course Pre-Test'];
        $moduleAssign = $byTitle['Module Assignment'];
        $reflection   = $byTitle['Reflection Journal'];

        // -----------------------------------------------------------------
        // Submissions. Student 3 (Maria Lopez Santos) was enrolled with
        // nothing to show; student 1 (Juan Dela Cruz) is a new enrollee
        // built as a strong performer. Student 4 (Angela Cruz) is left
        // with zero submissions on purpose — the roster's "hasn't engaged
        // yet" row, which the UI needs to render correctly without a
        // seeded row at all.
        // -----------------------------------------------------------------

        $submissionPlan = [

            // Maria Lopez Santos — graded on the two closed assessments,
            // still sitting ungraded on the one due later this week. That
            // ungraded row is what should surface in "Needs Review".
            [
                'student_id'  => 3,
                'assessment'  => $reflection,
                'score'       => 28,
                'status'      => 'Graded',
                'feedback'    => 'Reflection lacked specific examples — revisit the rubric before the next one.',
            ],
            [
                'student_id'  => 3,
                'assessment'  => $preTest,
                'score'       => 30,
                'status'      => 'Graded',
                'feedback'    => 'Passing score, but review the topics you missed.',
            ],
            [
                'student_id'  => 3,
                'assessment'  => $moduleAssign,
                'score'       => 0, // ungraded — column is NOT NULL, 0 matches the existing seeded convention
                'status'      => 'Submitted',
                'feedback'    => null,
            ],

            // Juan Dela Cruz — full house, strong across the board.
            [
                'student_id'  => 1,
                'assessment'  => $preTest,
                'score'       => 47,
                'status'      => 'Graded',
                'feedback'    => 'Excellent — near-perfect score.',
            ],
            [
                'student_id'  => 1,
                'assessment'  => $moduleAssign,
                'score'       => 92,
                'status'      => 'Graded',
                'feedback'    => 'Clean, well-documented code. Great work.',
            ],
            [
                'student_id'  => 1,
                'assessment'  => $reflection,
                'score'       => 44,
                'status'      => 'Graded',
                'feedback'    => 'Thoughtful reflection with concrete examples.',
            ],
        ];

        foreach ($submissionPlan as $entry) {

            $assessmentId = $entry['assessment']['id'];

            $exists = $this->db->table('assessment_submissions')
                ->where('assessment_id', $assessmentId)
                ->where('student_id', $entry['student_id'])
                ->countAllResults() > 0;

            if ($exists) {
                continue;
            }

            $isGraded = $entry['status'] === 'Graded';

            $percentage = $isGraded
                ? round(($entry['score'] / $entry['assessment']['total_points']) * 100, 2)
                : 0; // ungraded — column is NOT NULL, 0 matches the existing seeded convention

            $this->db->table('assessment_submissions')->insert([

                'assessment_id'       => $assessmentId,
                'student_id'          => $entry['student_id'],
                'attempt_number'      => 1,
                'started_at'          => date('Y-m-d H:i:s', strtotime('-3 hours')),
                'submitted_at'        => date('Y-m-d H:i:s', strtotime('-2 hours')),
                'time_spent'          => 1800,
                'score'               => $entry['score'],
                'percentage'          => $percentage,
                'is_late_submission'  => 0,
                'is_graded'           => $isGraded ? 1 : 0,
                'graded_by'           => $isGraded ? $gradedBy : null,
                'graded_at'           => $isGraded ? date('Y-m-d H:i:s', strtotime('-1 hour')) : null,
                'feedback'            => $entry['feedback'],
                'status'              => $entry['status'],

            ]);
        }

        // -----------------------------------------------------------------
        // Gradebook records: one per student with at least a partial
        // picture. Angela (student 4) has nothing graded yet, so her
        // record is seeded as Incomplete at 0% — that state needs to
        // exist somewhere for the "Incomplete" badge to ever be seen.
        // -----------------------------------------------------------------

        $gradebookPlan = [
            // Maria Lopez Santos: graded on 2 of 3 (78/100 = 58% -> Failed).
            ['student_id' => 3, 'earned' => 28 + 30, 'possible' => 50 + 50, 'remarks_note' => null],

            // Juan Dela Cruz: graded on all 3 (183/200 = 91.5% -> Passed).
            ['student_id' => 1, 'earned' => 47 + 92 + 44, 'possible' => 50 + 100 + 50, 'remarks_note' => null],

            // Angela Cruz: nothing submitted yet.
            ['student_id' => 4, 'earned' => 0, 'possible' => 0, 'remarks_note' => 'No submissions recorded yet.'],
        ];

        foreach ($gradebookPlan as $entry) {

            $exists = $this->db->table('gradebook_records')
                ->where('course_id', $courseId)
                ->where('student_id', $entry['student_id'])
                ->countAllResults() > 0;

            if ($exists) {
                continue;
            }

            $percentage = $entry['possible'] > 0
                ? round(($entry['earned'] / $entry['possible']) * 100, 2)
                : 0;

            $remarks = match (true) {
                $entry['possible'] === 0 => 'Incomplete',
                $percentage >= 75        => 'Passed',
                default                  => 'Failed',
            };

            $this->db->table('gradebook_records')->insert([

                'course_id'                => $courseId,
                'student_id'                => $entry['student_id'],
                'calculated_final_grade'    => $percentage,
                'overridden_final_grade'    => null,
                'is_override_final_grade'   => 0,
                'overall_percentage'        => $percentage,
                'remarks'                   => $remarks,
                'instructor_remarks'        => $entry['remarks_note'] ?? 'Initial grade generated from seeded assessment submissions.',
                'override_reason'           => null,
                'last_calculated_at'        => $now,
                'updated_by'                => $gradedBy,
                'status'                    => 'Active',
                'created_at'                => $now,
                'updated_at'                => null,

            ]);
        }
    }
}
