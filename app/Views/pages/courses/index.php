<?php 
$userRole = esc($user->getGroups()[0]);
?>

<?= $this->extend('layouts/app') ?>



<?= $this->section('content') ?>

<h1 class="text-3xl font-bold">My Courses</h1>

<div class="flex gap-3 px-1 py-3">

    <?php foreach ($courses as $course): ?>

        <a href="<?= site_url($userRole . '/courses/it101') ?>">IT101</a>

    <?php endforeach; ?>

</div>

<?= $this->endSection() ?>



<?= $this->section('scripts') ?>

<?= vite('resources/js/pages/courses.js') ?>

<?= $this->endSection() ?>