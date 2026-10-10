<?php
/**
 * One institution's feature flags: the answer for each, why, and a form to override it.
 *
 * @var array<string, mixed>                                      $flagInstitution
 * @var array<string, array{description: string, default: bool}> $flagDefs
 * @var array<string, array<string, mixed>>                       $flagDetail   from FeatureFlags::inspect($id)
 */
$input = 'rounded border border-gray-300 bg-white px-2 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-[#0040A6]/55';
$label = 'flex flex-col gap-1 text-xs font-medium text-gray-600';
$btn   = 'rounded border px-2.5 py-1 text-xs font-semibold';

$reasons = [
    'kill_switch'  => 'Switched off for everyone',
    'override_off' => 'Turned off for this institution',
    'override_on'  => 'Turned on for this institution',
    'plan'         => 'Included in its plan',
    'global_on'    => 'On for everyone by default',
    'default'      => 'Default',
];
?>

<?= $this->extend('layouts/admin') ?>

<?= $this->section('content') ?>

<a class="text-sm font-semibold text-[#0040A6] hover:underline" href="<?= site_url('admin/institutions') ?>">&larr; Institutions</a>

<h1 class="mt-2 text-2xl font-bold text-[#0040A6]">Feature flags</h1>
<p class="mt-1 text-sm text-gray-600"><?= esc($flagInstitution['name']) ?> <span class="font-mono text-xs">(<?= esc($flagInstitution['code']) ?>)</span></p>

<?= view('pages/admin/partials/institution-tabs', ['tabInstitution' => $flagInstitution, 'tabActive' => 'flags']) ?>

<?php if (session()->has('message')): ?>
    <p class="mt-4 rounded bg-green-50 px-3 py-2 text-sm text-green-700" role="status"><?= esc((string) session('message')) ?></p>
<?php endif; ?>
<?php if (session()->has('error')): ?>
    <p class="mt-4 rounded bg-red-50 px-3 py-2 text-sm text-red-700" role="alert"><?= esc((string) session('error')) ?></p>
<?php endif; ?>

<?php if ($flagDefs === []): ?>
    <p class="mt-6 rounded-lg bg-white p-6 text-center text-gray-500 shadow">No feature flags are defined.</p>
<?php endif; ?>

<div class="mt-6 flex flex-col gap-4">
    <?php foreach ($flagDefs as $key => $definition): ?>
        <?php
        $detail   = $flagDetail[$key];
        $override = $detail['override'];
        $state    = $override === null ? 'default' : ((int) $override['enabled'] === 1 ? 'on' : 'off');
        $reason   = $reasons[$detail['reason']] ?? $detail['reason'];

        if ($detail['reason'] === 'default') {
            $reason = 'Default (' . ($detail['default'] ? 'on' : 'off') . ')';
        }
        ?>
        <section class="rounded-lg bg-white p-5 shadow" data-flag="<?= esc($key) ?>" data-flag-enabled="<?= $detail['enabled'] ? '1' : '0' ?>" data-flag-reason="<?= esc($detail['reason']) ?>">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h2 class="font-mono text-base font-semibold text-[#0040A6]"><?= esc($key) ?></h2>
                    <p class="mt-1 max-w-2xl text-sm text-gray-700"><?= esc($definition['description']) ?></p>
                </div>
                <div class="text-right">
                    <span class="rounded-full px-2.5 py-0.5 text-xs font-semibold <?= $detail['enabled'] ? 'bg-green-100 text-green-800' : 'bg-gray-200 text-gray-700' ?>"><?= $detail['enabled'] ? 'On' : 'Off' ?></span>
                    <div class="mt-1 text-xs text-gray-500"><?= esc($reason) ?></div>
                </div>
            </div>

            <ul class="mt-3 list-inside list-disc text-xs text-gray-500">
                <?php if ($detail['global'] !== null): ?>
                    <li>
                        Everyone: <?= (int) $detail['global']['enabled'] === 1 ? 'on by default' : 'switched off' ?><?= $detail['global']['ends_on'] !== null ? ' until ' . esc($detail['global']['ends_on']) : '' ?><?= $detail['global']['ended'] ? ' (ended, not applied)' : '' ?>
                    </li>
                <?php endif; ?>
                <li>Plan: <?= $detail['plan'] ? 'includes it' : 'does not include it' ?></li>
                <?php if ($override !== null): ?>
                    <li>
                        This institution: <?= $state === 'on' ? 'turned on' : 'turned off' ?><?= $override['ends_on'] !== null ? ' until ' . esc($override['ends_on']) : '' ?><?= $override['ended'] ? ' (ended, not applied)' : '' ?>
                        <?php if ($override['note'] !== null): ?>: <?= esc($override['note']) ?><?php endif; ?>
                    </li>
                <?php endif; ?>
            </ul>

            <details class="mt-3">
                <summary class="<?= $btn ?> inline-block cursor-pointer border-gray-300 text-gray-700 hover:bg-gray-50">Override for this institution</summary>
                <form method="post" action="<?= site_url('admin/institutions/' . $flagInstitution['id'] . '/flags/' . $key) ?>" class="mt-3 grid max-w-2xl gap-3 sm:grid-cols-2">
                    <?= csrf_field() ?>
                    <label class="<?= $label ?>">For this institution
                        <select class="<?= $input ?>" name="state">
                            <option value="default" <?= $state === 'default' ? 'selected' : '' ?>>No override (follow plan and default)</option>
                            <option value="on" <?= $state === 'on' ? 'selected' : '' ?>>On</option>
                            <option value="off" <?= $state === 'off' ? 'selected' : '' ?>>Off</option>
                        </select>
                    </label>
                    <label class="<?= $label ?>">Last day <span class="font-normal text-gray-500">(optional)</span>
                        <input class="<?= $input ?>" type="date" name="ends_on" value="<?= esc((string) ($override['ends_on'] ?? '')) ?>">
                    </label>
                    <label class="<?= $label ?> sm:col-span-2">Note <span class="font-normal text-gray-500">(required when turning it on or off)</span>
                        <input class="<?= $input ?>" type="text" name="note" maxlength="500" value="<?= esc((string) ($override['note'] ?? '')) ?>">
                    </label>
                    <div class="sm:col-span-2">
                        <button type="submit" class="rounded bg-[#0040A6] px-4 py-1.5 text-sm font-semibold text-white hover:bg-[#002068]">Save</button>
                    </div>
                </form>
            </details>
        </section>
    <?php endforeach; ?>
</div>

<?= $this->endSection() ?>
