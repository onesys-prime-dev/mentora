<?php
/**
 * The page behind an invitation link: the invited person chooses a password.
 *
 * @var string               $inviteToken
 * @var array<string, mixed> $invitation  first_name, email, institution_name
 */
$min   = (int) config('Auth')->minimumPasswordLength;
$field = 'w-full h-12 px-4 rounded-full border border-gray-300 text-sm placeholder:text-gray-400 focus:outline-none focus:ring-2 focus:ring-blue-800/40 focus:border-blue-800';
?>
<?= $this->extend('layouts/auth') ?>

<?= $this->section('page') ?>invitation<?= $this->endSection() ?>

<?= $this->section('content') ?>

<div class="min-h-screen flex items-center justify-center px-4 py-10">

    <div class="w-full max-w-sm bg-white rounded-3xl shadow-xl px-8 py-10">

        <div class="flex items-center justify-start">
            <img class="h-10 w-auto" src="<?= base_url('assets/images/logos/Mentora_logo_type.png') ?>" alt="Mentora logo">
        </div>

        <div class="mt-8 flex flex-col gap-1 text-center">
            <h1 class="text-2xl font-bold font-display text-blue-800">Welcome, <?= esc($invitation['first_name']) ?></h1>
            <p class="text-sm text-gray-600">Choose a password to administer <strong><?= esc($invitation['institution_name']) ?></strong> on Mentora.</p>
        </div>

        <?php if (session('error') !== null): ?>
            <div class="mt-6 px-4 py-3 rounded-2xl text-sm bg-red-500/10 text-red-700" role="alert"><?= esc((string) session('error')) ?></div>
        <?php endif ?>

        <form class="mt-6 flex flex-col gap-4" action="<?= site_url('invitation/' . $inviteToken) ?>" method="post">

            <?= csrf_field() ?>

            <p class="text-sm text-gray-600">You will sign in with <strong><?= esc($invitation['email']) ?></strong> and this password.</p>

            <input type="password" name="password" class="<?= $field ?>" placeholder="Password" autocomplete="new-password" minlength="<?= $min ?>" required autofocus aria-label="Password">
            <input type="password" name="password_confirm" class="<?= $field ?>" placeholder="Repeat the password" autocomplete="new-password" minlength="<?= $min ?>" required aria-label="Repeat the password">

            <p class="text-xs text-gray-500">At least <?= $min ?> characters. Avoid common words and anything that contains your name or email.</p>

            <button type="submit" class="mt-2 h-12 rounded-full bg-blue-800 text-white text-sm font-bold shadow-md transition hover:bg-blue-900">
                Set password
            </button>

        </form>

    </div>

</div>

<?= $this->endSection() ?>
