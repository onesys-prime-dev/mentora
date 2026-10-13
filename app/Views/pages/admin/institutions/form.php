<?php
/**
 * Add / edit form. When editing, the code is shown but cannot be changed:
 * it identifies the institution everywhere.
 *
 * @var string                  $formHeading
 * @var array<string, mixed>|null $institution null when adding
 * @var array<string, mixed>      $formValues
 */
$isEdit = $institution !== null;
$action = $isEdit ? site_url('admin/institutions/' . $institution['id']) : site_url('admin/institutions');

$primary = 'rounded-full bg-brand px-5 py-2 text-sm font-semibold text-white transition hover:bg-brand-dark focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/50';
$cancel  = 'rounded-full border border-gray-300 bg-white px-5 py-2 text-sm font-semibold text-[#3D3D3D] transition hover:bg-gray-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/50';
?>

<?= $this->extend('layouts/admin') ?>

<?= $this->section('content') ?>

<div class="mx-auto max-w-lg">

    <?= view('components/page-head', ['title' => $formHeading, 'icon' => 'hat', 'backUrl' => site_url('admin/institutions'), 'backLabel' => 'Institutions'], ['saveData' => false]) ?>

    <?php if (! $isEdit): ?>
        <div class="mt-5"><?= view('pages/admin/partials/onboarding-steps', ['obStep' => 1]) ?></div>
    <?php endif; ?>

    <?php if (session()->has('error')): ?>
        <div class="mt-4"><?= view('components/alert', ['tone' => 'danger', 'text' => (string) session('error')], ['saveData' => false]) ?></div>
    <?php endif; ?>

    <form method="post" action="<?= $action ?>" class="mt-5 flex flex-col gap-4 rounded-2xl bg-white p-6 shadow-sm">
        <?= csrf_field() ?>

        <?= view('components/form-field', ['name' => 'name', 'label' => 'Name', 'value' => (string) $formValues['name'], 'maxlength' => 255, 'required' => true, 'attrs' => 'autofocus'], ['saveData' => false]) ?>

        <?php if ($isEdit): ?>
            <div class="flex flex-col gap-1">
                <label class="text-xs font-medium text-[#646464]" for="institution-code">Code</label>
                <input id="institution-code" type="text" value="<?= esc((string) $formValues['code']) ?>" disabled aria-describedby="institution-code-help" class="w-full rounded-lg border border-gray-200 bg-gray-50 px-3 py-2 font-mono text-sm text-[#646464]">
                <p id="institution-code-help" class="text-xs text-[#646464]">The code identifies this institution everywhere and cannot be changed.</p>
            </div>
        <?php else: ?>
            <?= view('components/form-field', ['name' => 'code', 'label' => 'Code', 'value' => (string) $formValues['code'], 'maxlength' => 50, 'required' => true, 'help' => '2 to 50 letters, numbers, dashes or underscores. It cannot be changed later.', 'attrs' => 'autocapitalize="characters"'], ['saveData' => false]) ?>
        <?php endif; ?>

        <div class="flex flex-wrap items-center justify-end gap-3">
            <a class="<?= $cancel ?>" href="<?= site_url('admin/institutions') ?>">Cancel</a>
            <button type="submit" class="<?= $primary ?>"><?= $isEdit ? 'Save' : 'Add institution and continue' ?></button>
        </div>
    </form>
</div>

<?= $this->endSection() ?>
