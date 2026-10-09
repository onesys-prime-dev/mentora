<?php
/**
 * Add / edit a plan.
 *
 * @var string                    $formHeading
 * @var array<string, mixed>|null $plan       null when adding
 * @var array<string, mixed>      $formValues
 */
$isEdit = $plan !== null;
$action = $isEdit ? site_url('admin/plans/' . $plan['id']) : site_url('admin/plans');
$input  = 'rounded border border-gray-300 bg-white px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-[#0040A6]/55';
$hint   = 'text-xs font-normal text-gray-500';
?>

<?= $this->extend('layouts/admin') ?>

<?= $this->section('content') ?>

<div class="mx-auto max-w-xl">
    <a class="text-sm font-semibold text-[#0040A6] hover:underline" href="<?= site_url('admin/plans') ?>">&larr; Plans</a>

    <div class="mt-3 rounded-lg bg-white p-6 shadow">
        <h1 class="text-xl font-bold text-[#0040A6]"><?= esc($formHeading) ?></h1>

        <?php if ($isEdit): ?>
            <p class="mt-3 rounded bg-amber-50 px-3 py-2 text-sm text-amber-800">
                Saving changes what every institution on this plan gets, straight away.
            </p>
        <?php endif; ?>

        <?php if (session()->has('error')): ?>
            <p class="mt-4 rounded bg-red-50 px-3 py-2 text-sm text-red-700" role="alert"><?= esc((string) session('error')) ?></p>
        <?php endif; ?>

        <form method="post" action="<?= $action ?>" class="mt-4 flex flex-col gap-4">
            <?= csrf_field() ?>

            <label class="flex flex-col gap-1 text-sm font-medium text-gray-700">Name
                <input class="<?= $input ?>" type="text" name="name" value="<?= esc((string) $formValues['name']) ?>" maxlength="100" required autofocus>
            </label>

            <label class="flex flex-col gap-1 text-sm font-medium text-gray-700">Description <span class="<?= $hint ?>">Optional</span>
                <textarea class="<?= $input ?>" name="description" rows="2" maxlength="1000"><?= esc((string) $formValues['description']) ?></textarea>
            </label>

            <label class="flex flex-col gap-1 text-sm font-medium text-gray-700">Features granted
                <textarea class="<?= $input ?> font-mono" name="features" rows="3" placeholder="one feature key per line"><?= esc((string) $formValues['features']) ?></textarea>
                <span class="<?= $hint ?>">Feature keys, separated by new lines or commas. Lower-case letters, numbers, dots, dashes and underscores.</span>
            </label>

            <fieldset class="flex flex-col gap-3">
                <legend class="text-sm font-medium text-gray-700">Limits <span class="<?= $hint ?>">Leave empty for unlimited</span></legend>

                <label class="flex flex-col gap-1 text-sm text-gray-700">Student seats
                    <input class="<?= $input ?>" type="number" min="1" name="seat_limit" value="<?= esc((string) $formValues['seat_limit']) ?>">
                </label>
                <label class="flex flex-col gap-1 text-sm text-gray-700">Storage (MB)
                    <input class="<?= $input ?>" type="number" min="1" name="storage_limit_mb" value="<?= esc((string) $formValues['storage_limit_mb']) ?>">
                </label>
                <label class="flex flex-col gap-1 text-sm text-gray-700">AI generations per term
                    <input class="<?= $input ?>" type="number" min="1" name="ai_generation_limit" value="<?= esc((string) $formValues['ai_generation_limit']) ?>">
                </label>
            </fieldset>

            <div class="flex gap-3">
                <button type="submit" class="rounded bg-[#0040A6] px-4 py-2 text-sm font-semibold text-white hover:bg-[#002068]"><?= $isEdit ? 'Save' : 'Add plan' ?></button>
                <a class="px-3 py-2 text-sm font-semibold text-gray-600 hover:underline" href="<?= site_url('admin/plans') ?>">Cancel</a>
            </div>
        </form>
    </div>
</div>

<?= $this->endSection() ?>
