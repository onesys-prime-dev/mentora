<?php
/**
 * Shown to a parent who is not linked to any student yet.
 */
?>

<?= $this->extend('layouts/app') ?>

<?= $this->section('content') ?>

<div class="flex flex-col gap-8 py-3">

    <?= view('components/page-head', [
        'title'       => 'Parent',
        'icon'        => 'user',
        'description' => 'Your children\'s progress at a glance.',
    ], ['saveData' => false]) ?>

    <?= view('components/empty-state', [
        'icon'  => 'info',
        'title' => 'You are not linked to a student yet',
        'text'  => 'Your school links a parent to their child. Once that is done, your child\'s courses and grades appear here. Ask your institution\'s administrator if this is a mistake.',
    ], ['saveData' => false]) ?>

</div>

<?= $this->endSection() ?>
