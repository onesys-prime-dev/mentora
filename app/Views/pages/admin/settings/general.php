<?php
/**
 * @var array<string, bool|int|string> $genValues
 * @var list<string>                   $genZones
 */
$value = static fn (string $key): string => (string) old($key, (string) $genValues[$key]);

$zoneOptions = [];

foreach ($genZones as $zone) {
    $zoneOptions[$zone] = $zone;
}

$field   = static fn (array $spec): string => view('components/form-field', $spec, ['saveData' => false]);
$primary = 'rounded-full bg-brand px-5 py-2 text-sm font-semibold text-white transition hover:bg-brand-dark focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/50';
$legend  = 'text-lg font-bold text-brand';
?>

<?= $this->extend('layouts/admin') ?>

<?= $this->section('content') ?>

<div class="rounded-3xl bg-white p-5 shadow-sm sm:p-8" data-page-panel>

<div class="mx-auto max-w-2xl">
    <?= view('components/page-head', [
        'title'       => 'General settings',
        'icon'        => 'settings',
        'description' => 'Apply to the whole platform. Changing them needs your password and is recorded in the audit log.',
        'backUrl'     => site_url('admin/dashboard'),
        'backLabel'   => 'Admin home',
    ], ['saveData' => false]) ?>

    <?php if (session()->has('message')): ?>
        <div class="mt-4"><?= view('components/alert', ['tone' => 'success', 'text' => (string) session('message')], ['saveData' => false]) ?></div>
    <?php endif; ?>
    <?php if (session()->has('error')): ?>
        <div class="mt-4"><?= view('components/alert', ['tone' => 'danger', 'text' => (string) session('error')], ['saveData' => false]) ?></div>
    <?php endif; ?>

    <form method="post" action="<?= site_url('admin/settings/general') ?>" data-dialog-form class="mt-6 flex flex-col gap-6 rounded-2xl bg-white p-6 shadow-sm">
        <?= csrf_field() ?>

        <fieldset class="flex flex-col gap-3">
            <legend class="<?= $legend ?>">Identity</legend>
            <?= $field(['name' => 'systemName', 'label' => 'System name', 'value' => $value('systemName'), 'maxlength' => 80, 'required' => true]) ?>
            <?= $field(['name' => 'supportContact', 'label' => 'Support contact', 'value' => $value('supportContact'), 'maxlength' => 200, 'help' => 'An email address or a line of text people are told to use.']) ?>
            <?= $field(['name' => 'timezone', 'label' => 'Timezone', 'type' => 'select', 'options' => $zoneOptions, 'value' => $value('timezone')]) ?>
        </fieldset>

        <fieldset class="flex flex-col gap-3">
            <legend class="<?= $legend ?>">Maintenance</legend>
            <?= $field(['name' => 'maintenanceMode', 'label' => 'Maintenance mode: everyone except system administrators is turned away', 'type' => 'checkbox', 'value' => old('maintenanceMode', $genValues['maintenanceMode'] ? '1' : '') === '1']) ?>
            <?= $field(['name' => 'maintenanceMessage', 'label' => 'Message shown while it is on', 'type' => 'textarea', 'value' => $value('maintenanceMessage'), 'maxlength' => 500, 'required' => true]) ?>
        </fieldset>

        <fieldset class="flex flex-col gap-3">
            <legend class="<?= $legend ?>">Announcement</legend>
            <?= $field(['name' => 'announcement', 'label' => 'A line shown at the top of every page', 'value' => $value('announcement'), 'maxlength' => 500, 'help' => 'Leave empty for none. People can hide it for themselves.']) ?>
        </fieldset>

        <fieldset class="flex flex-col gap-3">
            <legend class="<?= $legend ?>">Subscriptions and backups</legend>
            <p class="text-xs text-[#646464]">The default timeline for every institution that has no timeline of its own, in days after the end date, and how old the last good backup may get before an alert.</p>
            <div class="grid gap-3 sm:grid-cols-2">
                <?= $field(['name' => 'expiryWarningDays', 'label' => 'Warn this many days before the end', 'type' => 'number', 'value' => $value('expiryWarningDays'), 'required' => true, 'attrs' => 'min="0" max="365"']) ?>
                <?= $field(['name' => 'expiryGraceDays', 'label' => 'Grace period (full access) in days', 'type' => 'number', 'value' => $value('expiryGraceDays'), 'required' => true, 'attrs' => 'min="0" max="365"']) ?>
                <?= $field(['name' => 'expiryReadOnlyDays', 'label' => 'Read-only period in days', 'type' => 'number', 'value' => $value('expiryReadOnlyDays'), 'required' => true, 'attrs' => 'min="0" max="365"']) ?>
                <?= $field(['name' => 'backupAlertHours', 'label' => 'Alert when the last good backup is older than (hours)', 'type' => 'number', 'value' => $value('backupAlertHours'), 'required' => true, 'attrs' => 'min="1" max="720"']) ?>
            </div>
        </fieldset>

        <p class="text-xs font-medium text-[#C33939]" data-form-error hidden></p>

        <div class="flex justify-end">
            <button type="submit" class="<?= $primary ?>">Save</button>
        </div>
    </form>
</div>

</div>

<?= $this->endSection() ?>
