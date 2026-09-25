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

    <div 
        class="flex items-center gap-5 px-10 py-6 rounded-3xl bg-cyan-200 bg-cover bg-center bg-no-repeat text-white overflow-hidden"
        style="background-image: url('<?= base_url('assets/images/placeholder/course-tech-1.png') ?>');">

        <div class="w-20 h-20 flex justify-center items-center rounded-[100%] bg-blue-900">
            <?= icon('code', 'h-10 w-10') ?>
        </div>

        <div class="flex flex-col">
            <span class="text-xl">
                <?= esc($course['course_code']) . ' - ' . esc($course['section_code']) ?>
            </span>

            <h1 class="text-3xl font-bold">
                <?= esc($course['course_title']) ?>
            </h1>
        </div>

    </div>

    <div class="flex flex-col">

        <div class="flex gap-2 ml-10">

            <?php foreach ($features as $feature => $label): ?>

                <button
                    class="course-feature px-6 py-1 cursor-pointer text-white bg-blue-900 rounded-t-2xl"
                    data-course-feature="<?= esc($feature) ?>"
                >
                    <?= esc($label) ?>
                </button>

            <?php endforeach; ?>

        </div>

        <div 
            class="w-full bg-white rounded-3xl"
            id="course-content"
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

    'browserBaseUrl' => site_url($userRole . '/courses/' . $course['offering_id'] . '/' . $course['slug']),

    'apiBaseUrl' => site_url($userRole . '/api/courses/' . $course['offering_id']),

    'activeFeature' => $activeFeature,

]) ?>;
</script>

<?= $this->endSection() ?>