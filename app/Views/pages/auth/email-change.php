<?php
/**
 * The page behind the link sent to a new email address: ask, done, or a link that cannot be used.
 *
 * @var string $state    ask|done|problem
 * @var string $text     the new address (ask, done) or the reason (problem)
 * @var string $token    the link's token (ask)
 * @var bool   $signedIn
 */
$heading = ['ask' => 'Confirm new email', 'done' => 'Email changed', 'problem' => "This link can't be used"][$state];
$link    = 'inline-flex items-center text-blue-800 font-medium hover:underline pointer-coarse:min-h-11';
?>
<?= $this->extend('layouts/auth') ?>

<?= $this->section('page') ?>email-change<?= $this->endSection() ?>

<?= $this->section('content') ?>

<div class="min-h-dvh flex items-center justify-center px-gutter pt-safe pb-safe">

    <div class="w-full max-w-sm my-10 bg-white rounded-3xl shadow-xl px-6 xs:px-8 py-10">

        <div class="flex items-center justify-start">
            <img class="h-10 w-auto" src="<?= base_url('assets/images/logos/Mentora_logo_type.png') ?>" alt="Mentora logo">
        </div>

        <div class="mt-8 flex flex-col gap-2 text-center">
            <h1 class="text-2xl font-bold font-display text-blue-800"><?= esc($heading) ?></h1>
            <?php if ($state === 'ask'): ?>
                <p class="text-sm text-gray-600">Your Mentora sign-in email will change to <strong class="break-all text-gray-900"><?= esc($text) ?></strong>.</p>
            <?php elseif ($state === 'done'): ?>
                <p class="text-sm text-gray-600" role="status" data-email-changed>Your email address is now <?= esc($text) ?></p>
            <?php else: ?>
                <p class="text-sm text-gray-600" role="alert" data-email-change-problem><?= esc($text) ?></p>
            <?php endif; ?>
        </div>

        <?php if ($state === 'ask'): ?>
            <form class="mt-6" method="post" action="<?= esc(site_url('email-change/' . $token), 'attr') ?>">
                <?= csrf_field() ?>
                <button type="submit" class="w-full rounded-full bg-blue-800 px-5 py-2.5 text-sm font-semibold text-white transition hover:bg-blue-900 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-blue-800/50 pointer-coarse:min-h-11">Confirm new email</button>
            </form>
        <?php else: ?>
            <p class="mt-6 text-center text-sm text-gray-600">
                <?php if ($signedIn): ?>
                    <a class="<?= $link ?>" href="<?= site_url('profile') ?>">Go to your profile</a>
                <?php else: ?>
                    <a class="<?= $link ?>" href="<?= site_url('login') ?>">Sign in</a>
                <?php endif; ?>
            </p>
        <?php endif; ?>

    </div>

</div>

<?= $this->endSection() ?>
