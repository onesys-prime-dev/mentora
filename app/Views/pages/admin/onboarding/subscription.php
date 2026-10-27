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

<div class="rounded-3xl bg-white p-5 shadow-sm sm:p-8" data-page-panel>

<div class="mx-auto max-w-3xl">
    <?= view('components/page-head', ['title' => 'Onboard ' . $onbInstitution['name'], 'icon' => 'hat', 'backUrl' => site_url('admin/institutions'), 'backLabel' => 'Institutions'], ['saveData' => false]) ?>
    <p class="mt-1 text-sm text-[#646464]"><span class="font-mono text-xs"><?= esc($onbInstitution['code']) ?></span> &middot; pending: nothing here can be used until onboarding is finished.</p>

    <?= view('pages/admin/partials/onboarding-steps', ['obStep' => 2]) ?>

    <?php if (session()->has('message')): ?>
        <div class="mt-4"><?= view('components/alert', ['tone' => 'success', 'text' => (string) session('message')], ['saveData' => false]) ?></div>
    <?php endif; ?>
    <?php if (session()->has('error')): ?>
        <div class="mt-4"><?= view('components/alert', ['tone' => 'danger', 'text' => (string) session('error')], ['saveData' => false]) ?></div>
    <?php endif; ?>

    <div class="mt-6 rounded-2xl bg-white p-6 shadow-sm">
        <h2 class="text-lg font-semibold text-brand">Plan and first subscription</h2>
        <p class="mt-1 text-sm text-[#646464]">Pick the plan and the period the institution has paid for. Nothing is saved yet: the subscription starts when you finish the last step.</p>

        <?php if ($onbPlans === []): ?>
            <div class="mt-4"><?= view('components/alert', ['tone' => 'warning', 'text' => 'There is no plan to choose from. Create a plan first, then come back.'], ['saveData' => false]) ?>
            <p class="mt-2 text-sm"><a class="font-semibold text-brand underline" href="<?= site_url('admin/plans/new') ?>">Create a plan</a></p></div>
        <?php endif; ?>

        <form method="post" action="<?= site_url('admin/institutions/' . $onbInstitution['id'] . '/onboarding/subscription') ?>" class="mt-4 grid gap-3 sm:grid-cols-2">
            <?= csrf_field() ?>
            <?= view('pages/admin/partials/term-fields', ['tfCurrency' => $onbCurrency, 'tfPlans' => $onbPlans, 'tfPlan' => null, 'tfValues' => $onbValues]) ?>
            <div class="flex flex-wrap items-center justify-end gap-3 sm:col-span-2">
                <a class="rounded-full border border-gray-300 bg-white px-5 py-2 text-sm font-semibold text-[#3D3D3D] transition hover:bg-gray-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/50" href="<?= site_url('admin/institutions') ?>">Finish later</a>
                <button type="submit" class="rounded-full bg-brand px-5 py-2 text-sm font-semibold text-white transition hover:bg-brand-dark focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/50">Continue</button>
            </div>
        </form>
    </div>
</div>

</div>

<?= $this->endSection() ?>
