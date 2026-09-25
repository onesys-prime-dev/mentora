<?php
/**
 * Grades reads differently depending on who's looking: a student sees their
 * own standing and a per-assessment breakdown, an instructor sees a list of
 * assessments to grade — click one, land in Grade Work for it. Branching
 * here (rather than two controller actions) matches how assessments/index.php
 * already decides what to show per $userRole.
 *
 * Every array below has a placeholder fallback so the design renders and
 * reads correctly before GradeService is wired to real queries — same
 * intent as the dashboard widgets' `?? [...]` pattern, but done through
 * setVar() here rather than a bare local assignment.
 *
 * That distinction matters: $this->include() re-extracts the partial's
 * variables from $this->tempData — the data the *controller* originally
 * passed to view() — not from whatever local variables this script happens
 * to have assigned. A plain `$roster = $roster ?? [...]` here would only
 * ever be visible inside this file; the partial would still see $roster as
 * undefined. setVar() is what actually pushes a value into tempData so the
 * include below can see it.
 */
$userRole = esc($user->getGroups()[0]);
?>

<div class="p-10 flex flex-col gap-10">

    <?php if ($userRole === 'instructor'): ?>

        <?php
        $classSummary = $classSummary ?? [
            'average_percentage' => 87.4,
            'passing_count'      => 24,
            'total_students'     => 28,
            'needs_review'       => 3,
        ];

        $assessmentGroups = $assessmentGroups ?? [
            'Quizzes' => [
                ['id' => 1, 'title' => 'Quiz 1 - Number Systems', 'due_date' => '2026-07-11', 'total_points' => 10, 'graded_count' => 26, 'needs_review_count' => 2],
                ['id' => 2, 'title' => 'Quiz 2 - Boolean Logic',  'due_date' => '2026-07-18', 'total_points' => 10, 'graded_count' => 0,  'needs_review_count' => 0],
            ],
            'Assignments' => [
                ['id' => 3, 'title' => 'Reflection Paper', 'due_date' => '2026-07-20', 'total_points' => 100, 'graded_count' => 20, 'needs_review_count' => 8],
            ],
        ];
        ?>

        <?php
        $this->setVar('classSummary', $classSummary);
        $this->setVar('assessmentGroups', $assessmentGroups);
        ?>

        <?= $this->include('pages/courses/features/grades/partials/instructor-summary') ?>

        <?= $this->include('pages/courses/features/grades/partials/assessment-list') ?>

    <?php else: ?>

        <?php
        $summary = $summary ?? [
            'overall_percentage' => 89.5,
            'points_earned'      => 179,
            'points_possible'    => 200,
            'remarks'            => 'Passed',
        ];

        $assessments = $assessments ?? [
            ['id' => 1, 'title' => 'Quiz 1 - Number Systems', 'assessment_type' => 'Quizzes', 'due_date' => '2026-07-11', 'total_points' => 10, 'submission_status' => 'Graded', 'submission_score' => 9],
            ['id' => 2, 'title' => 'Quiz 2 - Boolean Logic',  'assessment_type' => 'Quizzes', 'due_date' => '2026-07-18', 'total_points' => 10, 'submission_status' => 'Graded', 'submission_score' => 8],
            ['id' => 3, 'title' => 'Reflection Paper',        'assessment_type' => 'Assignments', 'due_date' => '2026-07-20', 'total_points' => 100, 'submission_status' => 'Graded', 'submission_score' => 87],
            ['id' => 4, 'title' => 'Midterm Exam',             'assessment_type' => 'Exams', 'due_date' => '2026-08-01', 'total_points' => 80, 'submission_status' => 'Submitted', 'submission_score' => null],
        ];
        ?>

        <?php
        $this->setVar('summary', $summary);
        $this->setVar('assessments', $assessments);
        ?>

        <?= $this->include('pages/courses/features/grades/partials/student-summary') ?>

        <?= $this->include('pages/courses/features/grades/partials/assessment-breakdown') ?>

    <?php endif; ?>

</div>
