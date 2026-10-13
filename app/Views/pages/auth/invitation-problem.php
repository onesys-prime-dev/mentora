<?php
/**
 * An invitation link that cannot be used (unknown, already used, replaced or expired).
 *
 * @var string $inviteProblem why, in words fit to show
 */
?>
<?= $this->extend('layouts/auth') ?>

<?= $this->section('page') ?>invitation<?= $this->endSection() ?>

<?= $this->section('content') ?>

<div class="min-h-screen flex items-center justify-center px-4 py-10">

    <div class="w-full max-w-sm bg-white rounded-3xl shadow-xl px-8 py-10">

        <div class="flex items-center justify-start">
            <img class="h-10 w-auto" src="<?= base_url('assets/images/logos/Mentora_logo_type.png') ?>" alt="Mentora logo">
        </div>

        <div class="mt-8 flex flex-col gap-2 text-center">
            <h1 class="text-2xl font-bold font-display text-blue-800">This link can't be used</h1>
            <p class="text-sm text-gray-600" role="alert" data-invitation-problem><?= esc($inviteProblem) ?></p>
        </div>

        <p class="mt-6 text-center text-sm text-gray-600">
            <a class="text-blue-800 font-medium hover:underline" href="<?= site_url('login') ?>">Go to sign in</a>
        </p>

    </div>

</div>

<?= $this->endSection() ?>
