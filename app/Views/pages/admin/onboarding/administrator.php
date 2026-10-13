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
$primary = 'rounded-full bg-brand px-5 py-2 text-sm font-semibold text-white transition hover:bg-brand-dark focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/50';
$later   = 'rounded-full border border-gray-300 bg-white px-5 py-2 text-sm font-semibold text-[#3D3D3D] transition hover:bg-gray-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/50';
?>

<?= $this->extend('layouts/admin') ?>

<?= $this->section('content') ?>

<div class="mx-auto max-w-3xl">
    <?= view('components/page-head', ['title' => 'Onboard ' . $onbInstitution['name'], 'icon' => 'hat', 'backUrl' => site_url('admin/institutions'), 'backLabel' => 'Institutions'], ['saveData' => false]) ?>
    <p class="mt-1 text-sm text-[#646464]"><span class="font-mono text-xs"><?= esc($onbInstitution['code']) ?></span> &middot; pending: nothing here can be used until onboarding is finished.</p>

    <?= view('pages/admin/partials/onboarding-steps', ['obStep' => 3]) ?>

    <?php if (session()->has('error')): ?>
        <div class="mt-4"><?= view('components/alert', ['tone' => 'danger', 'text' => (string) session('error')], ['saveData' => false]) ?></div>
    <?php endif; ?>

    <div class="mt-6 rounded-2xl bg-white p-6 shadow-sm" data-onboarding-summary>
        <div class="flex items-start justify-between gap-3">
            <h2 class="text-lg font-semibold text-brand">Subscription to be started</h2>
            <a class="text-sm font-semibold text-brand hover:underline" href="<?= site_url('admin/institutions/' . $onbInstitution['id'] . '/onboarding/subscription') ?>">Change</a>
        </div>
        <p class="mt-2 text-sm text-gray-700">
            <strong><?= esc($onbPlanName) ?></strong>, from <strong><?= esc($onbTerm['period_start']) ?></strong> to <strong><?= esc($onbTerm['period_end']) ?></strong>.
        </p>
    </div>

    <div class="mt-6 rounded-2xl bg-white p-6 shadow-sm">
        <h2 class="text-lg font-semibold text-brand">First institutional administrator</h2>
        <p class="mt-1 text-sm text-[#646464]">
            They are emailed a link to set their own password. The link works once and expires after <?= esc((string) $onbValidityDays) ?> days; you can send a new one later.
            The subscription starts and the institution becomes usable when you finish.
        </p>

        <form method="post" action="<?= site_url('admin/institutions/' . $onbInstitution['id'] . '/onboarding/administrator') ?>" class="mt-4 grid gap-4 sm:grid-cols-2">
            <?= csrf_field() ?>

            <?= view('components/form-field', ['name' => 'first_name', 'label' => 'First name', 'value' => (string) $onbValues['first_name'], 'maxlength' => 100, 'required' => true, 'attrs' => 'autofocus'], ['saveData' => false]) ?>
            <?= view('components/form-field', ['name' => 'last_name', 'label' => 'Last name', 'value' => (string) $onbValues['last_name'], 'maxlength' => 100, 'required' => true], ['saveData' => false]) ?>
            <?= view('components/form-field', ['name' => 'email', 'label' => 'Email address', 'type' => 'email', 'value' => (string) $onbValues['email'], 'maxlength' => 254, 'required' => true, 'span' => 'sm:col-span-2', 'help' => 'Check it carefully: the link is sent here, and whoever opens it becomes the administrator.'], ['saveData' => false]) ?>

            <div class="flex flex-wrap items-center justify-end gap-3 sm:col-span-2">
                <a class="<?= $later ?>" href="<?= site_url('admin/institutions') ?>">Finish later</a>
                <button type="submit" class="<?= $primary ?>">Finish onboarding</button>
            </div>
        </form>
    </div>
</div>

<?= $this->endSection() ?>
