<?php
/** Shown to an institutional administrator whose account is linked to no institution. */
?>

<?= $this->extend('layouts/app') ?>

<?= $this->section('content') ?>

<div class="flex flex-col gap-6 py-3">

    <?= view('components/page-head', ['title' => 'Institution', 'icon' => 'hat'], ['saveData' => false]) ?>

    <div class="rounded-2xl bg-white/70" data-no-institution>
        <?= view('components/empty-state', [
            'icon'  => 'lock',
            'title' => 'Your account is not linked to an institution',
            'text'  => 'Ask the Mentora team to link your account, then sign in again.',
        ], ['saveData' => false]) ?>
    </div>

</div>

<?= $this->endSection() ?>
