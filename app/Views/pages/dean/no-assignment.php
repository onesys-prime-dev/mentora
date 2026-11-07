<?php
/**
 * Shown to a dean who has no active college assignment.
 */
?>

<?= $this->extend('layouts/app') ?>

<?= $this->section('content') ?>

<div class="flex flex-col gap-8 py-3">

    <?= view('components/page-head', [
        'title'       => 'Dean',
        'icon'        => 'hat',
        'description' => 'Your college at a glance.',
    ], ['saveData' => false]) ?>

    <?= view('components/empty-state', [
        'icon'  => 'info',
        'title' => 'You are not assigned to a college yet',
        'text'  => 'Your institution\'s administrator assigns a dean to a college. Once that is done, your college\'s courses and tools appear here.',
    ], ['saveData' => false]) ?>

</div>

<?= $this->endSection() ?>
