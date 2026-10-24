<?php
/**
 * Step 1 of the emailed sign-in code (system administrators): confirm the
 * address the code goes to. Replaces Shield's own view, whose content lands
 * in a section this app's auth layout does not render.
 *
 * @var CodeIgniter\Shield\Entities\User $user the administrator who has entered their password
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
            <h1 class="text-2xl font-bold font-display text-blue-800">One more step</h1>
            <p class="text-sm text-gray-600">We'll email you a 6-digit code to finish signing in. Confirm your email address to send it.</p>
        </div>

        <?php if (session('error') !== null): ?>
            <div class="mt-6 px-4 py-3 rounded-2xl text-sm bg-red-500/10 text-red-700" role="alert"><?= esc((string) session('error')) ?></div>
        <?php endif ?>

        <form class="mt-6 flex flex-col gap-4" action="<?= url_to('auth-action-handle') ?>" method="post">

            <?= csrf_field() ?>

            <input
                type="email"
                id="email"
                name="email"
                inputmode="email"
                autocomplete="email"
                placeholder="Email"
                aria-label="Email"
                value="<?= esc((string) old('email', $user->email)) ?>"
                required
                class="w-full h-12 px-4 rounded-full border border-gray-300 text-sm placeholder:text-gray-400 focus:outline-none focus:ring-2 focus:ring-blue-800/40 focus:border-blue-800">

            <button type="submit" class="mt-2 h-12 rounded-full bg-blue-800 text-white text-sm font-bold shadow-md transition hover:bg-blue-900">
                Send code
            </button>

            <p class="text-center text-sm text-gray-600">
                Not you? <button type="submit" formaction="<?= site_url('logout') ?>" formnovalidate class="text-blue-800 font-medium hover:underline">Sign in as someone else</button>
            </p>

        </form>

    </div>

</div>

<?= $this->endSection() ?>
