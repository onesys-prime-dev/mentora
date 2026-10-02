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
$userRole = esc($user->getGroups()[0]);
?>

<div class="p-10 flex flex-col gap-10">

    <?= view('components/page-header', ['icon' => 'grades', 'title' => 'Grades'], ['saveData' => false]) ?>

    <?php if ($userRole === 'instructor'): ?>

        <?= $this->include('pages/courses/features/grades/partials/gradebook-toolbar') ?>

        <?= $this->include('pages/courses/features/grades/partials/instructor-summary') ?>

        <?= $this->include('pages/courses/features/grades/partials/assessment-list') ?>

    <?php else: ?>

        <?= $this->include('pages/courses/features/grades/partials/student-summary') ?>

        <?= $this->include('pages/courses/features/grades/partials/grade-categories') ?>

        <?= $this->include('pages/courses/features/grades/partials/assessment-breakdown') ?>

    <?php endif; ?>

</div>

<?php if ($userRole === 'instructor'): ?>
    <?= $this->include('pages/courses/features/grades/partials/modals/grade-sheet-import') ?>
<?php endif; ?>
