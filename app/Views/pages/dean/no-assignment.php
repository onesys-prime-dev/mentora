<?php
/**
 * Shown to a dean who has no active department assignment.
 */
?>

<?= $this->extend('layouts/app') ?>

<?= $this->section('content') ?>

<div class="flex flex-col gap-8 py-3">

    <?= view('components/page-head', [
        'title'       => 'Dean',
        'icon'        => 'hat',
        'description' => 'Your department at a glance.',
    ], ['saveData' => false]) ?>

    <?= view('components/empty-state', [
        'icon'  => 'info',
        'title' => 'You are not assigned to a department yet',
        'text'  => 'Your institution\'s administrator assigns a dean to a department. Once that is done, your department\'s courses and tools appear here.',
    ], ['saveData' => false]) ?>

</div>

<?= $this->endSection() ?>
