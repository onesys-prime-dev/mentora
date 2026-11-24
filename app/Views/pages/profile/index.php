<?php
/**
 * The owner's own profile.
 *
 * @var array<string, mixed> $p      ProfileReader output for the signed-in person
 * @var array<string, mixed> $own    what only the owner sees: official, student_number, portal_on
 * @var bool $isOwner always true here: the body draws the owner's own cards
 * @var array<string, mixed> $avatar AvatarData row
 * @var array<string, mixed>|null $edit the owner's editable values; null when there is no profile row to edit
 * @var bool $canPreview whether to show the Me / As a student sees it toggle
 * @var bool $preview    the student preview is on
 * @var array<string, mixed>|null $previewP what a student would see; null = nothing
 * @var array<string, array{label: string, dialog: string}> $checklist what is still empty
 * @var array{has: bool, on: bool} $photo the owner's photo state
 */
?>

<?= $this->extend('layouts/app') ?>

<?= $this->section('content') ?>

<div class="flex flex-col gap-4 py-3">

    <?= view('components/page-head', [
        'title'       => 'My profile',
        'icon'        => 'user',
        'description' => $user->inGroup('admin') ? "This is your account's profile." : 'This is what people in your school see when they open your profile.',
    ], ['saveData' => false]) ?>

    <?php if ($canPreview): ?>
        <?php $seg = static fn (bool $on): string => 'inline-flex items-center rounded-full px-4 py-1.5 text-sm font-semibold transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/50 pointer-coarse:min-h-11 ' . ($on ? 'bg-brand text-white' : 'text-brand hover:bg-brand-tint'); ?>
        <nav class="inline-flex w-fit gap-1 rounded-full border border-brand/30 bg-white p-1" aria-label="Preview" data-profile-preview-toggle>
            <a href="<?= esc(site_url('profile'), 'attr') ?>" class="<?= $seg(! $preview) ?>" <?= $preview ? '' : 'aria-current="page"' ?>>Me</a>
            <a href="<?= esc(site_url('profile') . '?preview=student', 'attr') ?>" class="<?= $seg($preview) ?>" <?= $preview ? 'aria-current="page"' : '' ?>>As a student sees it</a>
        </nav>
    <?php endif; ?>

    <?php if ($preview): ?>
        <?php if ($previewP === null): ?>
            <p class="rounded-2xl bg-white p-4 text-sm text-[#646464] shadow-sm sm:p-6">Students in your school can't see your profile.</p>
        <?php else: ?>
            <?= view('pages/profile/partials/profile-body', ['p' => $previewP, 'isOwner' => false, 'avatar' => $avatar], ['saveData' => false]) ?>
        <?php endif; ?>
    <?php else: ?>
    <?php if ($checklist !== []): ?>
        <?= view('pages/profile/partials/checklist', ['items' => $checklist], ['saveData' => false]) ?>
    <?php endif; ?>

    <?= view('pages/profile/partials/profile-body', ['p' => $p, 'isOwner' => $isOwner, 'own' => $own, 'avatar' => $avatar, 'edit' => $edit], ['saveData' => false]) ?>

    <?php if ($edit !== null): ?>
        <?= view('pages/profile/partials/dialogs/photo', ['id' => 'profile-photo', 'title' => 'Profile photo', 'action' => site_url('profile/photo'), 'removeUrl' => site_url('profile/photo/remove'), 'has' => $photo['has'], 'on' => $photo['on']], ['saveData' => false]) ?>
        <?= view('pages/profile/partials/dialogs/identity', ['edit' => $edit], ['saveData' => false]) ?>
        <?php if (array_key_exists('bio', $p)): ?>
            <?= view('pages/profile/partials/dialogs/about', ['p' => $p], ['saveData' => false]) ?>
        <?php endif; ?>
        <?php if ($edit['staff'] && ($p['staff_info'] ?? null) !== null): ?>
            <?= view('pages/profile/partials/dialogs/staff', ['p' => $p], ['saveData' => false]) ?>
        <?php endif; ?>
    <?php endif; ?>
    <?php if (($own['correction'] ?? null) !== null): ?>
        <?= view('pages/profile/partials/dialogs/correction', ['correction' => $own['correction']], ['saveData' => false]) ?>
    <?php elseif (($own['official_edit'] ?? null) !== null): ?>
        <?= view('pages/profile/partials/dialogs/official', ['now' => $own['official_edit']], ['saveData' => false]) ?>
    <?php endif; ?>
    <?php endif; ?>

</div>

<?= $this->endSection() ?>
