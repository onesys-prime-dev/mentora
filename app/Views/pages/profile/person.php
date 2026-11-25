<?php
/**
 * Someone else's profile as a page (/people/{id}): links from notifications and emails, no-JS, small phones.
 *
 * @var array<string, mixed> $p          ProfileReader::forViewer output
 * @var array<string, mixed> $avatar     AvatarData row
 * @var bool                 $canMessage the viewer may start a conversation
 */
?>

<?= $this->extend('layouts/app') ?>

<?= $this->section('content') ?>

<div class="flex flex-col gap-4 py-3">

    <?= view('components/page-head', ['title' => $p['display_name'], 'icon' => 'user'], ['saveData' => false]) ?>

    <?= view('pages/profile/partials/person-panel', ['p' => $p, 'avatar' => $avatar, 'canMessage' => $canMessage], ['saveData' => false]) ?>

</div>

<?= $this->endSection() ?>
