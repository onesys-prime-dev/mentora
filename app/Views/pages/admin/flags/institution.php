<?php
/**
 * One institution's feature flags: the answer for each, why, and a form to override it.
 *
 * @var array<string, mixed>                                      $flagInstitution
 * @var array<string, array{description: string, default: bool}> $flagDefs
 * @var array<string, array<string, mixed>>                       $flagDetail   from FeatureFlags::inspect($id)
 */
$input = 'rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm focus:border-brand/60 focus:outline-none focus:ring-2 focus:ring-brand/40';
$label = 'flex flex-col gap-1 text-xs font-medium text-[#646464]';
$btn   = 'rounded-full border px-3 py-1 text-xs font-semibold transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/50';

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

<?= view('components/page-head', ['title' => 'Feature flags', 'icon' => 'pin', 'backUrl' => site_url('admin/institutions'), 'backLabel' => 'Institutions'], ['saveData' => false]) ?>
<p class="mt-1 text-sm text-[#646464]"><?= esc($flagInstitution['name']) ?> <span class="font-mono text-xs">(<?= esc($flagInstitution['code']) ?>)</span></p>

<?= view('pages/admin/partials/institution-tabs', ['tabInstitution' => $flagInstitution, 'tabActive' => 'flags']) ?>

<?php if (session()->has('message')): ?>
    <div class="mt-4"><?= view('components/alert', ['tone' => 'success', 'text' => (string) session('message')], ['saveData' => false]) ?></div>
<?php endif; ?>
<?php if (session()->has('error')): ?>
    <div class="mt-4"><?= view('components/alert', ['tone' => 'danger', 'text' => (string) session('error')], ['saveData' => false]) ?></div>
<?php endif; ?>

<?php if ($flagDefs === []): ?>
    <p class="mt-6 rounded-lg bg-white p-6 text-center text-[#646464] shadow">No feature flags are defined.</p>
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
                    <h2 class="font-mono text-base font-semibold text-brand"><?= esc($key) ?></h2>
                    <p class="mt-1 max-w-2xl text-sm text-gray-700"><?= esc($definition['description']) ?></p>
                </div>
                <div class="text-right">
                    <span class="rounded-full px-2.5 py-0.5 text-xs font-semibold <?= $detail['enabled'] ? 'bg-[#E8F5EC] text-[#2B7549]' : 'bg-gray-200 text-gray-700' ?>"><?= $detail['enabled'] ? 'On' : 'Off' ?></span>
                    <div class="mt-1 text-xs text-[#646464]"><?= esc($reason) ?></div>
                </div>
            </div>

            <ul class="mt-3 list-inside list-disc text-xs text-[#646464]">
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
                <summary class="<?= $btn ?> inline-block cursor-pointer border-gray-300 bg-white text-[#3D3D3D] hover:bg-gray-50">Override for this institution</summary>
                <form method="post" action="<?= site_url('admin/institutions/' . $flagInstitution['id'] . '/flags/' . $key) ?>" class="mt-3 grid max-w-2xl gap-3 sm:grid-cols-2">
                    <?= csrf_field() ?>
                    <label class="<?= $label ?>">For this institution
                        <select class="<?= $input ?>" name="state">
                            <option value="default" <?= $state === 'default' ? 'selected' : '' ?>>No override (follow plan and default)</option>
                            <option value="on" <?= $state === 'on' ? 'selected' : '' ?>>On</option>
                            <option value="off" <?= $state === 'off' ? 'selected' : '' ?>>Off</option>
                        </select>
                    </label>
                    <label class="<?= $label ?>">Last day <span class="font-normal text-[#646464]">(optional)</span>
                        <input class="<?= $input ?>" type="date" name="ends_on" value="<?= esc((string) ($override['ends_on'] ?? '')) ?>">
                    </label>
                    <label class="<?= $label ?> sm:col-span-2">Note <span class="font-normal text-[#646464]">(required when turning it on or off)</span>
                        <input class="<?= $input ?>" type="text" name="note" maxlength="500" value="<?= esc((string) ($override['note'] ?? '')) ?>">
                    </label>
                    <div class="sm:col-span-2">
                        <button type="submit" class="rounded-full bg-brand px-5 py-2 text-sm font-semibold text-white transition hover:bg-brand-dark focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/50">Save</button>
                    </div>
                </form>
            </details>
        </section>
    <?php endforeach; ?>
</div>

<?= $this->endSection() ?>
