<?php 
$userRole = esc($user->getGroups()[0]);

$courseTitle = esc($course['course_code']) . ' - ' . esc($course['course_title']);

$features = [
    'home' => 'Home',
    'modules' => 'Modules',
    'resources' => 'Resources',
    'discussions' => 'Discussions',
    'assessments' => 'Assessments',
    'grade' => 'Grade',
];
?>

<?= $this->extend('layouts/app') ?>



<?= $this->section('content') ?>

<h1 class="text-3xl font-bold">
    <?= $courseTitle ?>
</h1>

<div class="flex py-3">

    <?php foreach ($features as $feature => $label): ?>

        <button
            class="course-feature px-6 py-1 cursor-pointer hover:border-b"
            data-course-feature="<?= esc($feature) ?>"
        >
            <?= esc($label) ?>
        </button>

    <?php endforeach; ?>

</div>

<div 
    class="w-full bg-amber-400"
    id="course-content"
>
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