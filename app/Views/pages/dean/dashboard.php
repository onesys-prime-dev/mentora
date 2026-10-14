<?php
/**
 * The dean's landing page until the dean dashboard is built.
 *
 * @var array{name: string} $department
 */
?>

<?= $this->extend('layouts/app') ?>

<?= $this->section('content') ?>

<div class="flex flex-col gap-8 py-3">

    <?= view('components/page-head', [
        'title'       => (string) $department['name'],
        'icon'        => 'hat',
        'description' => 'You are the dean of this department.',
    ], ['saveData' => false]) ?>

</div>

<?= $this->endSection() ?>
