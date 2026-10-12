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
$input  = 'rounded border border-gray-300 bg-white px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand/55';
?>

<?= $this->extend('layouts/admin') ?>

<?= $this->section('content') ?>

<div class="mx-auto max-w-lg">
    <a class="text-sm font-semibold text-brand hover:underline" href="<?= site_url('admin/institutions') ?>">&larr; Institutions</a>

    <?php if (! $isEdit): ?>
        <?= view('pages/admin/partials/onboarding-steps', ['obStep' => 1]) ?>
    <?php endif; ?>

    <div class="mt-3 rounded-lg bg-white p-6 shadow">
        <h1 class="text-xl font-bold text-brand"><?= esc($formHeading) ?></h1>

        <?php if (session()->has('error')): ?>
            <p class="mt-4 rounded bg-red-50 px-3 py-2 text-sm text-red-700" role="alert"><?= esc((string) session('error')) ?></p>
        <?php endif; ?>

        <form method="post" action="<?= $action ?>" class="mt-4 flex flex-col gap-4">
            <?= csrf_field() ?>

            <label class="flex flex-col gap-1 text-sm font-medium text-gray-700">Name
                <input class="<?= $input ?>" type="text" name="name" value="<?= esc((string) $formValues['name']) ?>" maxlength="255" required autofocus>
            </label>

            <label class="flex flex-col gap-1 text-sm font-medium text-gray-700">Code
                <?php if ($isEdit): ?>
                    <input class="<?= $input ?> bg-gray-100 font-mono text-gray-600" type="text" value="<?= esc((string) $formValues['code']) ?>" disabled>
                    <span class="text-xs font-normal text-gray-500">The code identifies this institution everywhere and cannot be changed.</span>
                <?php else: ?>
                    <input class="<?= $input ?> font-mono uppercase" type="text" name="code" value="<?= esc((string) $formValues['code']) ?>" maxlength="50" required>
                    <span class="text-xs font-normal text-gray-500">2 to 50 letters, numbers, dashes or underscores. It cannot be changed later.</span>
                <?php endif; ?>
            </label>

            <div class="flex gap-3">
                <button type="submit" class="rounded bg-brand px-4 py-2 text-sm font-semibold text-white hover:bg-brand-dark"><?= $isEdit ? 'Save' : 'Add institution and continue' ?></button>
                <a class="px-3 py-2 text-sm font-semibold text-gray-600 hover:underline" href="<?= site_url('admin/institutions') ?>">Cancel</a>
            </div>
        </form>
    </div>
</div>

<?= $this->endSection() ?>
