<?php
/*
|--------------------------------------------------------------------------
| Course Overview
|--------------------------------------------------------------------------
| Built by OverviewService, per role:
|   student    → Due This Week · Overdue · Current Grade; "Continue where you
|                left off", the progress card, what's due next
|   instructor → To Grade · Due This Week · Submission Rate · Needs
|                Attention; "Needs Your Attention", class progress by module,
|                what's closing soon
|
| What people act on comes first; the course facts (description, schedule,
| room…) and the recent-activity feed are reference material and sit in the
| side column.
|
| The metric cards are the Assessments tab's own partial, so both tabs read
| as one product.
*/
?>

<div class="p-10 flex flex-col gap-8">

    <?= $this->include('pages/courses/features/assessments/partials/overview/metrics') ?>

    <div class="grid grid-cols-1 gap-8 xl:grid-cols-5">

        <div class="flex min-w-0 flex-col gap-8 xl:col-span-3">

            <?= $this->include(
                $role === 'student'
                    ? 'pages/courses/features/overview/partials/continue-learning'
                    : 'pages/courses/features/overview/partials/needs-attention'
            ) ?>

            <?= $this->include('pages/courses/features/overview/partials/progress') ?>

            <?= $this->include('pages/courses/features/overview/partials/upcoming-deadlines') ?>

        </div>

        <div class="flex min-w-0 flex-col gap-6 xl:col-span-2">

            <?= $this->include('pages/courses/features/overview/partials/course-info') ?>

            <?= $this->include('pages/courses/features/overview/partials/recent-activity') ?>

        </div>

    </div>

</div>
