<?php
/**
 * Onboarding step 2: the plan and first subscription. Checked here, saved only
 * when the last step finishes.
 *
 * @var array<string, mixed>       $onbInstitution
 * @var list<array<string, mixed>> $onbPlans       plans that can be chosen (id, name)
 * @var string                     $onbCurrency
 * @var array<string, mixed>       $onbValues      answers kept from an earlier visit to this step
 */
?>

<?= $this->extend('layouts/admin') ?>

<?= $this->section('content') ?>

<div class="mx-auto max-w-3xl">
    <a class="text-sm font-semibold text-brand hover:underline" href="<?= site_url('admin/institutions') ?>">&larr; Institutions</a>

    <h1 class="mt-2 text-2xl font-bold text-brand">Onboard <?= esc($onbInstitution['name']) ?></h1>
    <p class="mt-1 text-sm text-gray-600"><span class="font-mono text-xs"><?= esc($onbInstitution['code']) ?></span> &middot; pending: nothing here can be used until onboarding is finished.</p>

    <?= view('pages/admin/partials/onboarding-steps', ['obStep' => 2]) ?>

    <?php if (session()->has('message')): ?>
        <p class="mt-4 rounded bg-green-50 px-3 py-2 text-sm text-green-700" role="status"><?= esc((string) session('message')) ?></p>
    <?php endif; ?>
    <?php if (session()->has('error')): ?>
        <p class="mt-4 rounded bg-red-50 px-3 py-2 text-sm text-red-700" role="alert"><?= esc((string) session('error')) ?></p>
    <?php endif; ?>

    <div class="mt-6 rounded-lg bg-white p-6 shadow">
        <h2 class="text-lg font-semibold text-brand">Plan and first subscription</h2>
        <p class="mt-1 text-sm text-gray-600">Pick the plan and the period the institution has paid for. Nothing is saved yet: the subscription starts when you finish the last step.</p>

        <?php if ($onbPlans === []): ?>
            <p class="mt-4 rounded bg-amber-50 px-3 py-2 text-sm text-amber-800" role="alert">There is no plan to choose from. <a class="font-semibold underline" href="<?= site_url('admin/plans/new') ?>">Create a plan</a> first, then come back.</p>
        <?php endif; ?>

        <form method="post" action="<?= site_url('admin/institutions/' . $onbInstitution['id'] . '/onboarding/subscription') ?>" class="mt-4 grid gap-3 sm:grid-cols-2">
            <?= csrf_field() ?>
            <?= view('pages/admin/partials/term-fields', ['tfCurrency' => $onbCurrency, 'tfPlans' => $onbPlans, 'tfPlan' => null, 'tfValues' => $onbValues]) ?>
            <div class="flex items-center gap-3 sm:col-span-2">
                <button type="submit" class="rounded bg-brand px-4 py-2 text-sm font-semibold text-white hover:bg-brand-dark">Continue</button>
                <a class="text-sm font-semibold text-gray-600 hover:underline" href="<?= site_url('admin/institutions') ?>">Finish later</a>
            </div>
        </form>
    </div>
</div>

<?= $this->endSection() ?>
