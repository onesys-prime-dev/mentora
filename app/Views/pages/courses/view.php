<?php 
$userRole = esc($user->getGroups()[0]);

$courseTitle = esc($course['course_code']) . ' - ' . esc($course['course_title']);

$courseUrl = $userRole . '/courses/' . $course['offering_id'] . '/' . $course['slug'];
?>

<?= $this->extend('layouts/app') ?>



<?= $this->section('content') ?>

<h1 class="text-3xl font-bold">
    <?= $courseTitle ?>
</h1>

<div class="flex py-3">

    <button 
        class="px-6 py-1 cursor-pointer hover:border-b"
        data-course-feature="home">
        Home
    </button>

    <button 
        class="px-6 py-1 cursor-pointer hover:border-b"
        data-course-feature="modules">
        Modules
    </button>

    <button 
        class="px-6 py-1 cursor-pointer hover:border-b"
        data-course-feature="resources">
        Resources
    </button>

    <button 
        class="px-6 py-1 cursor-pointer hover:border-b"
        data-course-feature="discussions">
        Discussions
    </button>

    <button 
        class="px-6 py-1 cursor-pointer hover:border-b"
        data-course-feature="assessments">
        Assessments
    </button>

    <button 
        class="px-6 py-1 cursor-pointer hover:border-b"
        data-course-feature="grade">
        Grade
    </button>

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

    'role' => $userRole,

    'baseUrl'       => site_url(),

    'courseBaseUrl' => site_url($userRole . '/courses/' . $course['offering_id'] . '/' . $course['slug']),

    'apiBaseUrl'    => site_url($userRole . '/api/courses/' . $course['offering_id']),

    'activeFeature' => $activeTab,

]) ?>;
</script>

<?= $this->endSection() ?>