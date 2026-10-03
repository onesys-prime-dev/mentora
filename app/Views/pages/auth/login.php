<?= $this->extend('layouts/auth') ?>

<?= $this->section('page') ?>login<?= $this->endSection() ?>

<?= $this->section('content') ?>

<div class="min-h-screen flex items-center justify-center px-4 py-10">

    <div class="w-full max-w-sm bg-white rounded-3xl shadow-xl px-8 py-10">

        <div class="flex items-center justify-start">
            <img
                class="h-10 w-auto"
                src="<?= base_url('assets/images/logos/Mentora_logo_type.png') ?>"
                alt="Mentora logo">
        </div>

        <div class="mt-8 flex flex-col gap-1 text-center">
            <h1 class="text-2xl font-bold font-display text-blue-800">
                Welcome Back!
            </h1>
            <p class="text-sm text-gray-600">
                Access resources and services with ease
            </p>
        </div>

        <?php if (session('error') !== null): ?>

            <div class="mt-6 px-4 py-3 rounded-2xl text-sm bg-red-500/10 text-red-700" role="alert">
                <?= esc(session('error')) ?>
            </div>

        <?php elseif (session('errors') !== null): ?>

            <div class="mt-6 px-4 py-3 rounded-2xl text-sm bg-red-500/10 text-red-700" role="alert">
                <?php if (is_array(session('errors'))): ?>
                    <?php foreach (session('errors') as $error): ?>
                        <div><?= esc($error) ?></div>
                    <?php endforeach ?>
                <?php else: ?>
                    <?= esc(session('errors')) ?>
                <?php endif ?>
            </div>

        <?php endif ?>

        <?php if (session('message') !== null): ?>

            <div class="mt-6 px-4 py-3 rounded-2xl text-sm bg-emerald-500/10 text-emerald-700" role="alert">
                <?= esc(session('message')) ?>
            </div>

        <?php endif ?>

        <form class="mt-6 flex flex-col gap-4" action="<?= url_to('login') ?>" method="post">

            <?= csrf_field() ?>

            <div class="relative">
                <input
                    type="email"
                    id="email"
                    name="email"
                    inputmode="email"
                    autocomplete="email"
                    placeholder="Email"
                    value="<?= old('email') ?>"
                    required
                    class="w-full h-12 pl-4 pr-11 rounded-full border border-gray-300 text-sm placeholder:text-gray-400 focus:outline-none focus:ring-2 focus:ring-blue-800/40 focus:border-blue-800">

                <span class="absolute right-4 top-1/2 -translate-y-1/2 text-gray-400" aria-hidden="true">
                    <svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M3 6.75A2.25 2.25 0 0 1 5.25 4.5h13.5A2.25 2.25 0 0 1 21 6.75v10.5A2.25 2.25 0 0 1 18.75 19.5H5.25A2.25 2.25 0 0 1 3 17.25V6.75Z" />
                        <path stroke-linecap="round" stroke-linejoin="round" d="m3.5 6.75 7.72 5.955c.463.357 1.107.357 1.57 0L20.5 6.75" />
                    </svg>
                </span>
            </div>

            <div class="relative">
                <input
                    type="password"
                    id="password"
                    name="password"
                    inputmode="text"
                    autocomplete="current-password"
                    placeholder="Password"
                    required
                    class="w-full h-12 pl-4 pr-11 rounded-full border border-gray-300 text-sm placeholder:text-gray-400 focus:outline-none focus:ring-2 focus:ring-blue-800/40 focus:border-blue-800">

                <button
                    type="button"
                    data-password-toggle="password"
                    aria-label="Show password"
                    aria-pressed="false"
                    class="absolute right-4 top-1/2 -translate-y-1/2 text-gray-400 hover:text-gray-600">

                    <!-- eye: shown while the password is masked -->
                    <svg data-password-icon="shown" class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 12S5.25 5.25 12 5.25 21.75 12 21.75 12 18.75 18.75 12 18.75 2.25 12 2.25 12Z" />
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 15a3 3 0 1 0 0-6 3 3 0 0 0 0 6Z" />
                    </svg>

                    <!-- eye-off: shown while the password is revealed -->
                    <svg data-password-icon="hidden" class="hidden w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M3.98 8.223A10.477 10.477 0 0 0 1.934 12c1.292 4.338 5.31 7.5 10.066 7.5a10.44 10.44 0 0 0 4.204-.878M6.228 6.228A10.45 10.45 0 0 1 12 4.5c4.756 0 8.774 3.162 10.066 7.5a10.522 10.522 0 0 1-4.293 5.774M6.228 6.228 3 3m3.228 3.228 3.65 3.65m7.894 7.894L21 21m-3.228-3.228-3.65-3.65m0 0a3 3 0 1 0-4.243-4.243m4.242 4.242L9.88 9.88" />
                    </svg>
                </button>
            </div>

            <div class="flex items-center justify-between text-sm">

                <?php if (setting('Auth.sessionConfig')['allowRemembering']): ?>
                    <label class="flex items-center gap-2 text-gray-700">
                        <input
                            type="checkbox"
                            name="remember"
                            class="w-4 h-4 rounded border-gray-300 text-blue-800 focus:ring-blue-800/40"
                            <?php if (old('remember')): ?>checked<?php endif ?>>
                        Remember me
                    </label>
                <?php else: ?>
                    <span></span>
                <?php endif ?>

                <?php if (setting('Auth.allowMagicLinkLogins')): ?>
                    <a class="text-blue-800 font-medium hover:underline" href="<?= url_to('magic-link') ?>">
                        Forgot Password?
                    </a>
                <?php endif ?>

            </div>

            <button
                type="submit"
                class="mt-2 h-12 rounded-full bg-blue-800 text-white text-sm font-bold shadow-md transition hover:bg-blue-900">
                Login
            </button>

            <?php if (setting('Auth.allowRegistration')): ?>
                <p class="text-center text-sm text-gray-600">
                    Need an account?
                    <a class="text-blue-800 font-medium hover:underline" href="<?= url_to('register') ?>">
                        Register
                    </a>
                </p>
            <?php endif ?>

        </form>

    </div>

</div>

<?= $this->endSection() ?>
