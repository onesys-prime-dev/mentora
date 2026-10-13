<?php 
$userRole = esc($user->getGroups()[0]);

$features = [
    'overview' => 'Overview',
    'announcements' => 'Announcements',
    'modules' => 'Modules',
    'resources' => 'Resources',
    'discussions' => 'Discussions',
    'assessments' => 'Assessments',
    'grades' => 'Grades',
];
?>

<?= $this->extend('layouts/app') ?>



<?= $this->section('content') ?>

<div class="flex justify-between items-center">
    <?= $this->include('components/back') ?>
</div>

<div class="flex flex-col gap-5">

    <?php
    // Per-course artwork can be supplied later via $course['banner_image'];
    // the scrim below keeps the text readable on any image, and the solid
    // brand colour underneath is the fallback when there is none.
    $bannerImage = $course['banner_image'] ?? base_url('assets/images/placeholder/course-tech-1.png');
    ?>

    <div
        class="relative overflow-hidden rounded-3xl bg-brand-dark bg-cover bg-center bg-no-repeat text-white"
        style="background-image: url('<?= esc($bannerImage, 'attr') ?>');">

        <div class="absolute inset-0 bg-linear-to-r from-brand-dark/90 via-brand-dark/65 to-brand-dark/20" aria-hidden="true"></div>

        <div class="relative flex items-center gap-4 px-5 py-5 sm:gap-5 sm:px-10 sm:py-6">

            <div class="flex h-14 w-14 shrink-0 items-center justify-center rounded-full bg-white/15 ring-1 ring-white/30 sm:h-20 sm:w-20">
                <?= icon('code', 'h-7 w-7 sm:h-10 sm:w-10') ?>
            </div>

            <div class="flex min-w-0 flex-col">

                <span class="text-sm font-medium text-white/85 sm:text-xl">
                    <?= esc($course['course_code']) . ' - ' . esc($course['section_code']) ?>
                </span>

                <h1 class="text-2xl font-bold sm:text-3xl">
                    <?= esc($course['course_title']) ?>
                </h1>

                <?php if (!empty($course['term_name'])): ?>
                    <span class="mt-1 text-xs text-white/80 sm:text-sm">
                        <?= esc($course['term_name']) ?><?= !empty($course['academic_year']) ? ' · ' . esc($course['academic_year']) : '' ?>
                    </span>
                <?php endif; ?>

            </div>

        </div>

    </div>

    <?php if (! empty($readOnlyView)): ?>
        <div class="px-4 sm:px-10" data-view-only>
            <?= view('components/lock-note', ['kind' => 'closed', 'text' => 'View only. You are looking at ' . $course['course_code'] . ' ' . $course['section_code'] . ' as an administrator of this institution: everything is shown as its instructor sees it, and nothing can be changed here.'], ['saveData' => false]) ?>
        </div>
    <?php endif; ?>

    <?php if (! empty($offeringClosed)): ?>
        <div class="px-4 sm:px-10" data-offering-closed>
            <?= view('components/lock-note', ['kind' => 'closed', 'text' => 'This course is closed. You can still look at it and download what is there, but nothing in it can be changed.'], ['saveData' => false]) ?>
        </div>
    <?php endif; ?>

    <div class="flex flex-col">

        <div class="flex gap-2 ml-4 overflow-x-auto sm:ml-10" role="tablist" aria-label="Course sections">

            <?php foreach ($features as $feature => $label): ?>

                <button
                    type="button"
                    role="tab"
                    aria-selected="false"
                    class="course-feature shrink-0 whitespace-nowrap px-4 py-1.5 cursor-pointer text-white bg-blue-900 hover:bg-blue-800 rounded-t-2xl focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-white sm:px-6"
                    data-course-feature="<?= esc($feature) ?>"
                >
                    <?= esc($label) ?>
                </button>

            <?php endforeach; ?>

        </div>

        <div 
            class="w-full bg-white rounded-3xl"
            id="course-content"
            role="tabpanel"
        >

    </div>

    </div>

</div>

<?= $this->endSection() ?>



<?= $this->section('scripts') ?>

<script>
window.coursesConfig = <?= json_encode([

    'offeringId' => $course['offering_id'],

    'slug' => $course['slug'],

    'role' => $userRole,

    'browserBaseUrl' => site_url(($courseUrlPrefix ?? $userRole) . '/courses/' . $course['offering_id'] . '/' . $course['slug']),

    'apiBaseUrl' => site_url(($courseUrlPrefix ?? $userRole) . '/api/courses/' . $course['offering_id']),

    'activeFeature' => $activeFeature,

]) ?>;
</script>

<?= $this->endSection() ?>