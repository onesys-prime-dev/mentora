<?php
/**
 * The owner's own profile.
 *
 * @var array<string, mixed> $p      ProfileReader output for the signed-in person
 * @var array<string, mixed> $own    what only the owner sees: official, student_number, portal_on
 * @var array<string, mixed> $avatar AvatarData row
 * @var array<string, mixed>|null $edit the owner's editable values; null when there is no profile row to edit
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

    <?= view('pages/profile/partials/profile-body', ['p' => $p, 'own' => $own, 'avatar' => $avatar, 'edit' => $edit], ['saveData' => false]) ?>

    <?php if ($edit !== null): ?>
        <?= view('pages/profile/partials/dialogs/identity', ['edit' => $edit], ['saveData' => false]) ?>
        <?php if (array_key_exists('bio', $p)): ?>
            <?= view('pages/profile/partials/dialogs/about', ['p' => $p], ['saveData' => false]) ?>
        <?php endif; ?>
        <?php if ($edit['staff'] && ($p['staff_info'] ?? null) !== null): ?>
            <?= view('pages/profile/partials/dialogs/staff', ['p' => $p], ['saveData' => false]) ?>
        <?php endif; ?>
    <?php endif; ?>

</div>

<?= $this->endSection() ?>
