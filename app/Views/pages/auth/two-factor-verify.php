<?php
/**
 * Step 2 of the emailed sign-in code (system administrators): type the code.
 * Replaces Shield's own view; see two-factor-show.php.
 */
?>
<?= $this->extend('layouts/auth') ?>

<?= $this->section('page') ?>two-factor<?= $this->endSection() ?>

<?= $this->section('content') ?>

<div class="min-h-screen flex items-center justify-center px-4 py-10">

    <div class="w-full max-w-sm bg-white rounded-3xl shadow-xl px-8 py-10">

        <div class="flex items-center justify-start">
            <img class="h-10 w-auto" src="<?= base_url('assets/images/logos/Mentora_logo_type.png') ?>" alt="Mentora logo">
        </div>

        <div class="mt-8 flex flex-col gap-1 text-center">
            <h1 class="text-2xl font-bold font-display text-blue-800">Enter your code</h1>
            <p class="text-sm text-gray-600">We've sent a 6-digit code to your email address.</p>
        </div>

        <?php if (ENVIRONMENT === 'development'): ?>
            <div class="mt-6 px-4 py-3 rounded-2xl text-sm bg-amber-500/10 text-amber-800" role="note">
                Development: the code is also written to today's file in <span class="font-mono">writable/logs</span> (search for "sign-in code").
            </div>
        <?php endif ?>

        <?php if (session('error') !== null): ?>
            <div class="mt-6 px-4 py-3 rounded-2xl text-sm bg-red-500/10 text-red-700" role="alert"><?= esc((string) session('error')) ?></div>
        <?php endif ?>

        <form class="mt-6 flex flex-col gap-4" action="<?= url_to('auth-action-verify') ?>" method="post">

            <?= csrf_field() ?>

            <input
                type="text"
                id="token"
                name="token"
                inputmode="numeric"
                pattern="[0-9]*"
                maxlength="6"
                autocomplete="one-time-code"
                placeholder="000000"
                aria-label="6-digit code"
                required
                autofocus
                class="w-full h-12 px-4 rounded-full border border-gray-300 text-center text-lg tracking-[0.4em] placeholder:text-gray-300 focus:outline-none focus:ring-2 focus:ring-blue-800/40 focus:border-blue-800">

            <button type="submit" class="mt-2 h-12 rounded-full bg-blue-800 text-white text-sm font-bold shadow-md transition hover:bg-blue-900">
                Confirm
            </button>

            <p class="text-center text-sm text-gray-600">
                <a class="text-blue-800 font-medium hover:underline" href="<?= url_to('auth-action-show') ?>">Send a new code</a>
                &middot;
                <button type="submit" formaction="<?= site_url('logout') ?>" formnovalidate class="text-blue-800 font-medium hover:underline">Sign in as someone else</button>
            </p>

        </form>

    </div>

</div>

<?= $this->endSection() ?>
