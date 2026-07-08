<?= $this->extend('layouts/app') ?>



<?= $this->section('content') ?>

<h2 class="text-3xl font-bold">

    Courses

</h2>

<?= $this->endSection() ?>



<?= $this->section('scripts') ?>

<?= vite('resources/js/pages/courses.js') ?>

<?= $this->endSection() ?>