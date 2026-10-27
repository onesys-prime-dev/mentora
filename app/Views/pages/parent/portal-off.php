<?php
/**
 * Shown to a parent while the parent portal is switched off (by Mentora or by
 * the institution). It says nothing about which, and carries no child data.
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
        'icon'  => 'lock',
        'title' => 'The parent portal is switched off',
        'text'  => 'Your institution is not using the parent portal right now. Nothing has been lost; contact your school.',
    ], ['saveData' => false]) ?>

</div>

<?= $this->endSection() ?>
