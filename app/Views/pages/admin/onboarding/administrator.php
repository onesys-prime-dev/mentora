<?php
/**
 * Onboarding step 3: the first institutional administrator. Finishing saves the
 * subscription, sends the invitation and activates the institution, together.
 *
 * @var array<string, mixed> $onbInstitution
 * @var array<string, mixed> $onbTerm          the subscription answers from step 2
 * @var string               $onbPlanName
 * @var int                  $onbValidityDays  how long the emailed link works
 * @var array<string, mixed> $onbValues        first_name, last_name, email as typed
 */
$input = 'rounded border border-gray-300 bg-white px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand/55';
$label = 'flex flex-col gap-1 text-sm font-medium text-gray-700';
?>

<?= $this->extend('layouts/admin') ?>

<?= $this->section('content') ?>

<div class="mx-auto max-w-3xl">
    <a class="text-sm font-semibold text-brand hover:underline" href="<?= site_url('admin/institutions') ?>">&larr; Institutions</a>

    <h1 class="mt-2 text-2xl font-bold text-brand">Onboard <?= esc($onbInstitution['name']) ?></h1>
    <p class="mt-1 text-sm text-gray-600"><span class="font-mono text-xs"><?= esc($onbInstitution['code']) ?></span> &middot; pending: nothing here can be used until onboarding is finished.</p>

    <?= view('pages/admin/partials/onboarding-steps', ['obStep' => 3]) ?>

    <?php if (session()->has('error')): ?>
        <p class="mt-4 rounded bg-red-50 px-3 py-2 text-sm text-red-700" role="alert"><?= esc((string) session('error')) ?></p>
    <?php endif; ?>

    <div class="mt-6 rounded-lg bg-white p-6 shadow" data-onboarding-summary>
        <div class="flex items-start justify-between gap-3">
            <h2 class="text-lg font-semibold text-brand">Subscription to be started</h2>
            <a class="text-sm font-semibold text-brand hover:underline" href="<?= site_url('admin/institutions/' . $onbInstitution['id'] . '/onboarding/subscription') ?>">Change</a>
        </div>
        <p class="mt-2 text-sm text-gray-700">
            <strong><?= esc($onbPlanName) ?></strong>, from <strong><?= esc($onbTerm['period_start']) ?></strong> to <strong><?= esc($onbTerm['period_end']) ?></strong>.
        </p>
    </div>

    <div class="mt-6 rounded-lg bg-white p-6 shadow">
        <h2 class="text-lg font-semibold text-brand">First institutional administrator</h2>
        <p class="mt-1 text-sm text-gray-600">
            They are emailed a link to set their own password. The link works once and expires after <?= esc((string) $onbValidityDays) ?> days; you can send a new one later.
            The subscription starts and the institution becomes usable when you finish.
        </p>

        <form method="post" action="<?= site_url('admin/institutions/' . $onbInstitution['id'] . '/onboarding/administrator') ?>" class="mt-4 grid gap-4 sm:grid-cols-2">
            <?= csrf_field() ?>

            <label class="<?= $label ?>">First name
                <input class="<?= $input ?>" type="text" name="first_name" value="<?= esc((string) $onbValues['first_name']) ?>" maxlength="100" required autofocus>
            </label>
            <label class="<?= $label ?>">Last name
                <input class="<?= $input ?>" type="text" name="last_name" value="<?= esc((string) $onbValues['last_name']) ?>" maxlength="100" required>
            </label>
            <label class="<?= $label ?> sm:col-span-2">Email address
                <input class="<?= $input ?>" type="email" name="email" value="<?= esc((string) $onbValues['email']) ?>" maxlength="254" required>
                <span class="text-xs font-normal text-gray-500">Check it carefully: the link is sent here, and whoever opens it becomes the administrator.</span>
            </label>

            <div class="flex items-center gap-3 sm:col-span-2">
                <button type="submit" class="rounded bg-brand px-4 py-2 text-sm font-semibold text-white hover:bg-brand-dark">Finish onboarding</button>
                <a class="text-sm font-semibold text-gray-600 hover:underline" href="<?= site_url('admin/institutions') ?>">Finish later</a>
            </div>
        </form>
    </div>
</div>

<?= $this->endSection() ?>
