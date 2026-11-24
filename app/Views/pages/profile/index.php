<?php
/**
 * The owner's own profile.
 *
 * @var array<string, mixed> $p      ProfileReader output for the signed-in person
 * @var array<string, mixed> $own    what only the owner sees: official, student_number, portal_on
 * @var array<string, mixed> $avatar AvatarData row
 */
?>

<?= $this->extend('layouts/app') ?>

<?= $this->section('content') ?>

<div class="flex flex-col gap-4 py-3">

    <?= view('components/page-head', [
        'title'       => 'My profile',
        'icon'        => 'user',
        'description' => 'This is what people in your school see when they open your profile.',
    ], ['saveData' => false]) ?>

    <?= view('pages/profile/partials/profile-body', ['p' => $p, 'own' => $own, 'avatar' => $avatar], ['saveData' => false]) ?>

</div>

<?= $this->endSection() ?>
