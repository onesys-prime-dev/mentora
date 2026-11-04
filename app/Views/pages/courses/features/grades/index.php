<?php
/**
 * Grades reads differently depending on who's looking: a student sees their
 * own standing and a per-assessment breakdown, an instructor sees a list of
 * assessments to grade — click one, land in Grade Work for it. Branching
 * here (rather than two controller actions) matches how assessments/index.php
 * already decides what to show per $userRole.
 *
 * All data comes from GradeService (real queries, no placeholders).
 */
$userRole = \App\Services\Courses\CourseViewer::seesInstructorView($user) ? 'instructor' : esc($user->getGroups()[0]);
?>

<div class="p-10 flex flex-col gap-10">

    <?php // The way to the Grades page across every course, for whoever has one (GradeService::allGradesLink). ?>
    <?= view('components/page-header', [
        'icon'    => 'grades',
        'title'   => 'Grades',
        'actions' => ! empty($allGrades)
            ? '<a href="' . esc($allGrades['href'], 'attr') . '" data-all-grades class="inline-flex min-h-9 items-center justify-center rounded-full border border-brand bg-white px-4 py-1.5 text-sm font-semibold text-brand transition hover:bg-brand-tint focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/55">' . esc($allGrades['label']) . '</a>'
            : '',
    ], ['saveData' => false]) ?>

    <?php if ($userRole === 'instructor'): ?>

        <?= $this->include('pages/courses/features/grades/partials/gradebook-toolbar') ?>

        <?= $this->include('pages/courses/features/grades/partials/instructor-summary') ?>

        <?= $this->include('pages/courses/features/grades/partials/assessment-list') ?>

    <?php else: ?>

        <?= $this->include('pages/courses/features/grades/partials/student-summary') ?>

        <?= $this->include('pages/courses/features/grades/partials/grade-categories') ?>

        <?= $this->include('pages/courses/features/grades/partials/manual-items') ?>

        <?= $this->include('pages/courses/features/grades/partials/assessment-breakdown') ?>

    <?php endif; ?>

</div>

<?php if ($userRole === 'instructor'): ?>
    <?= $this->include('pages/courses/features/grades/partials/modals/grade-sheet-import') ?>
<?php endif; ?>
