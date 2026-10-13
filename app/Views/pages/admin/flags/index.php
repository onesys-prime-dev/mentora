<?php
/**
 * Feature flags: each flag's global setting.
 *
 * @var array<string, array{description: string, default: bool}> $flagDefs
 * @var array<string, array<string, mixed>>                       $flagGlobal   from FeatureFlags::inspect(null)
 * @var array<string, array{on: int, total: int, overrides: int}> $flagAdoption
 */
$btn   = 'rounded-full border px-3 py-1 text-xs font-semibold transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/50';
?>

<?= $this->extend('layouts/admin') ?>

<?= $this->section('content') ?>

<?= view('components/page-head', ['title' => 'Feature flags', 'icon' => 'pin'], ['saveData' => false]) ?>
<p class="mt-2 max-w-3xl text-sm text-[#646464]">
    A flag decides whether a feature is on. Here you set it for everyone. <strong>Off is a kill switch</strong>: the feature is off for every
    institution, whatever else says. <strong>On</strong> makes it on by default for everyone. To change a flag for one institution, open
    its <em>Features</em> page from the institutions list.
</p>

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
        $global = $flagGlobal[$key]['global'];
        $state  = $global === null ? 'default' : ((int) $global['enabled'] === 1 ? 'on' : 'off');
        $count  = $flagAdoption[$key];

        $stateText = match (true) {
            $global === null                                  => 'Following its default (' . ($definition['default'] ? 'on' : 'off') . ') and each institution\'s plan.',
            $global['ended']                                  => 'Set ' . $state . ' for everyone, but that ended on ' . $global['ends_on'] . '. Following its default again.',
            $state === 'off'                                  => 'Switched off for everyone' . ($global['ends_on'] !== null ? ' until ' . $global['ends_on'] : '') . '.',
            default                                           => 'On for everyone by default' . ($global['ends_on'] !== null ? ' until ' . $global['ends_on'] : '') . '.',
        };
        ?>
        <section class="rounded-lg bg-white p-5 shadow" data-flag="<?= esc($key) ?>" data-flag-state="<?= esc($state) ?>">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h2 class="font-mono text-base font-semibold text-brand"><?= esc($key) ?></h2>
                    <p class="mt-1 max-w-2xl text-sm text-gray-700"><?= esc($definition['description']) ?></p>
                </div>
                <div class="text-right text-sm text-[#646464]">
                    <div><strong><?= esc((string) $count['on']) ?></strong> of <?= esc((string) $count['total']) ?> institutions have it on</div>
                    <div class="text-xs text-[#646464]"><?= esc((string) $count['overrides']) ?> with their own override</div>
                </div>
            </div>

            <p class="mt-3 text-sm <?= $state === 'off' && ! ($global['ended'] ?? false) ? 'font-semibold text-red-700' : 'text-gray-700' ?>"><?= esc($stateText) ?></p>
            <?php if ($global !== null && $global['note'] !== null): ?><p class="mt-1 text-xs text-[#646464]">Note: <?= esc($global['note']) ?></p><?php endif; ?>

            <details class="mt-3">
                <summary class="<?= $btn ?> inline-block cursor-pointer border-gray-300 bg-white text-[#3D3D3D] hover:bg-gray-50">Change</summary>
                <form data-dialog-form method="post" action="<?= site_url('admin/flags/' . $key . '/global') ?>" class="mt-3 grid max-w-2xl gap-3 sm:grid-cols-2">
                    <?= csrf_field() ?>
                    <?= view('components/form-field', ['name' => 'state', 'label' => 'For everyone', 'type' => 'select', 'options' => ['default' => 'Follow the default', 'on' => 'On for everyone', 'off' => 'Off for everyone (kill switch)'], 'value' => (string) $state], ['saveData' => false]) ?>
                    <?= view('components/form-field', ['name' => 'ends_on', 'label' => 'Last day (optional)', 'type' => 'date', 'value' => (string) ($global['ends_on'] ?? '')], ['saveData' => false]) ?>
                    <?= view('components/form-field', ['name' => 'note', 'label' => 'Note', 'value' => (string) ($global['note'] ?? ''), 'maxlength' => 500, 'span' => 'sm:col-span-2', 'help' => 'Required when turning it on or off.'], ['saveData' => false]) ?>
                    <div class="sm:col-span-2">
                        <button type="submit" class="rounded-full bg-brand px-5 py-2 text-sm font-semibold text-white transition hover:bg-brand-dark focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/50">Save</button>
                    </div>
                </form>
            </details>
        </section>
    <?php endforeach; ?>
</div>

<?= $this->endSection() ?>
