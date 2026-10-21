<?php
/**
 * The Overview tab for a parent: the course facts only (description,
 * instructor, schedule, room, term). Nothing of the student's own progress.
 *
 * @var array<string, mixed> $overview CourseOfferingModel::getCourseOverviewData()
 * @var list<array>          $schedule OverviewService::scheduleBlocks()
 * @var string               $role     'student': the facts-only form of the course-info partial
 */
?>

<div class="p-10 flex flex-col gap-8">

    <?= view('components/page-header', ['icon' => 'info', 'title' => 'Overview'], ['saveData' => false]) ?>

    <?= view('pages/courses/features/overview/partials/course-info', ['overview' => $overview, 'schedule' => $schedule, 'role' => $role], ['saveData' => false]) ?>

</div>
