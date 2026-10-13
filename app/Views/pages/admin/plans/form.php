<?php
/**
 * Add / edit a plan.
 *
 * @var string                     $formHeading
 * @var array<string, mixed>|null  $plan         null when adding
 * @var array<string, mixed>       $formValues
 * @var list<string>               $planFeatureKeys the feature flags that exist
 */
$isEdit = $plan !== null;
$action = $isEdit ? site_url('admin/plans/' . $plan['id']) : site_url('admin/plans');

$primary = 'rounded-full bg-brand px-5 py-2 text-sm font-semibold text-white transition hover:bg-brand-dark focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/50';
$cancel  = 'rounded-full border border-gray-300 bg-white px-5 py-2 text-sm font-semibold text-[#3D3D3D] transition hover:bg-gray-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/50';

$featureHelp = 'Feature keys, one per line or separated by commas. '
    . ($planFeatureKeys === [] ? 'There are no feature flags to choose from yet.' : 'Available: ' . implode(', ', $planFeatureKeys));
?>

<?= $this->extend('layouts/admin') ?>

<?= $this->section('content') ?>

<div class="mx-auto max-w-xl">

    <?= view('components/page-head', ['title' => $formHeading, 'icon' => 'points', 'backUrl' => site_url('admin/plans'), 'backLabel' => 'Plans'], ['saveData' => false]) ?>

    <?php if ($isEdit): ?>
        <div class="mt-4"><?= view('components/alert', ['tone' => 'warning', 'text' => 'Saving changes what every institution on this plan gets, straight away.'], ['saveData' => false]) ?></div>
    <?php endif; ?>

    <?php if (session()->has('error')): ?>
        <div class="mt-4"><?= view('components/alert', ['tone' => 'danger', 'text' => (string) session('error')], ['saveData' => false]) ?></div>
    <?php endif; ?>

    <form data-dialog-form method="post" action="<?= $action ?>" class="mt-5 flex flex-col gap-4 rounded-2xl bg-white p-6 shadow-sm">
        <?= csrf_field() ?>

        <?= view('components/form-field', ['name' => 'name', 'label' => 'Name', 'value' => (string) $formValues['name'], 'maxlength' => 100, 'required' => true, 'attrs' => 'autofocus'], ['saveData' => false]) ?>
        <?= view('components/form-field', ['name' => 'description', 'label' => 'Description (optional)', 'type' => 'textarea', 'value' => (string) $formValues['description'], 'maxlength' => 1000], ['saveData' => false]) ?>
        <?= view('components/form-field', ['name' => 'features', 'label' => 'Features granted', 'type' => 'textarea', 'value' => (string) $formValues['features'], 'placeholder' => 'one feature key per line', 'help' => $featureHelp], ['saveData' => false]) ?>

        <fieldset class="flex flex-col gap-3">
            <legend class="text-sm font-semibold text-[#1A1C1E]">Limits <span class="text-xs font-normal text-[#646464]">Leave empty for unlimited</span></legend>

            <?= view('components/form-field', ['name' => 'seat_limit', 'label' => 'Student seats', 'type' => 'number', 'value' => (string) $formValues['seat_limit'], 'attrs' => 'min="1"'], ['saveData' => false]) ?>
            <?= view('components/form-field', ['name' => 'storage_limit_mb', 'label' => 'Storage (MB)', 'type' => 'number', 'value' => (string) $formValues['storage_limit_mb'], 'attrs' => 'min="1"'], ['saveData' => false]) ?>
            <?= view('components/form-field', ['name' => 'ai_generation_limit', 'label' => 'AI generations per term', 'type' => 'number', 'value' => (string) $formValues['ai_generation_limit'], 'attrs' => 'min="1"'], ['saveData' => false]) ?>
        </fieldset>

        <div class="flex flex-wrap items-center justify-end gap-3">
            <a class="<?= $cancel ?>" href="<?= site_url('admin/plans') ?>">Cancel</a>
            <button type="submit" class="<?= $primary ?>"><?= $isEdit ? 'Save' : 'Add plan' ?></button>
        </div>
    </form>
</div>

<?= $this->endSection() ?>
