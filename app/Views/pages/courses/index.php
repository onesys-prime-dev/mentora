<?php
$userRole = esc($user->getGroups()[0]);
$isInstructor = $userRole === 'instructor';
$loadError = $loadError ?? false;
?>

<?= $this->extend('layouts/app') ?>

<?= $this->section('content') ?>

<div class="flex justify-between items-center">
    <?= $this->include('components/back') ?>
</div>

<div class="flex flex-col gap-6 py-3">

    <div class="flex flex-col gap-1">
        <h1 class="flex items-center gap-4 text-2xl font-bold text-[#0040A6]">
            <?= icon('hat', 'h-6 w-6 shrink-0 text-[#0040A6]') ?>
            Courses
        </h1>
        <p class="text-sm text-[#3D3D3D]">
            <?= $isInstructor ? 'The courses you teach this term.' : 'The courses you are enrolled in.' ?>
        </p>
    </div>

    <?php if ($loadError): ?>

        <div class="flex flex-col items-center justify-center gap-3 rounded-2xl border border-[#C33939]/30 bg-white px-8 py-14 text-center" role="alert">
            <?= icon('warning', 'h-10 w-10 text-[#C33939]') ?>
            <span class="text-sm font-semibold text-[#1A1C1E]">We could not load your courses</span>
            <span class="text-xs text-[#646464]">Something went wrong on our side. Your courses have not gone anywhere.</span>
            <a href="<?= site_url($userRole . '/courses') ?>" class="rounded-full bg-[#0040A6] px-5 py-2 text-sm font-semibold text-white transition hover:bg-[#002068] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#0040A6]/55 focus-visible:ring-offset-2">Try again</a>
        </div>

    <?php elseif ($courses === []): ?>

        <div class="rounded-2xl bg-white/70">
            <?= view('components/empty-state', [
                'icon' => 'hat',
                'title' => 'No courses yet',
                'text' => $isInstructor
                    ? 'Courses assigned to you will appear here.'
                    : 'Courses you are enrolled in will appear here.',
            ], ['saveData' => false]) ?>
        </div>

    <?php else: ?>

        <ul class="grid grid-cols-1 gap-5 sm:grid-cols-2 xl:grid-cols-3 2xl:grid-cols-4" aria-label="Your courses">

            <?php foreach ($courses as $course): ?>
                <li>
                    <?= view('pages/courses/partials/course-card', [
                        'course' => $course,
                        'userRole' => $userRole,
                        'isInstructor' => $isInstructor,
                    ], ['saveData' => false]) ?>
                </li>
            <?php endforeach; ?>

        </ul>

    <?php endif; ?>

</div>

<?= $this->endSection() ?>
