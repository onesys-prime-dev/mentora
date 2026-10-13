<?php
/**
 * @var array<string, bool|int|string> $genValues
 * @var list<string>                   $genZones
 */
$input = 'w-full rounded border border-gray-300 bg-white px-2 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-brand/55';
$short = 'w-28 rounded border border-gray-300 bg-white px-2 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-brand/55';
$value = static fn (string $key): string => (string) old($key, (string) $genValues[$key]);
$label = 'flex flex-col gap-1 text-sm text-gray-700';
?>

<?= $this->extend('layouts/admin') ?>

<?= $this->section('content') ?>

<div class="mx-auto max-w-2xl">
    <a class="text-sm font-semibold text-brand hover:underline" href="<?= site_url('admin/dashboard') ?>">&larr; Admin home</a>

    <h1 class="mt-2 text-2xl font-bold text-brand">General settings</h1>
    <p class="mt-1 text-sm text-gray-600">Apply to the whole platform. Changing them needs your password and is recorded in the audit log.</p>

    <?php if (session()->has('message')): ?>
        <p class="mt-4 rounded bg-green-50 px-3 py-2 text-sm text-green-700" role="status"><?= esc((string) session('message')) ?></p>
    <?php endif; ?>
    <?php if (session()->has('error')): ?>
        <p class="mt-4 rounded bg-red-50 px-3 py-2 text-sm text-red-700" role="alert"><?= esc((string) session('error')) ?></p>
    <?php endif; ?>

    <form method="post" action="<?= site_url('admin/settings/general') ?>" class="mt-6 flex flex-col gap-6 rounded-lg bg-white p-6 shadow">
        <?= csrf_field() ?>

        <fieldset class="flex flex-col gap-3">
            <legend class="text-lg font-semibold text-brand">Identity</legend>
            <label class="<?= $label ?>">System name
                <input class="<?= $input ?>" type="text" name="systemName" maxlength="80" value="<?= esc($value('systemName')) ?>" required>
            </label>
            <label class="<?= $label ?>">Support contact <span class="text-xs text-gray-500">(an email address or a line of text people are told to use)</span>
                <input class="<?= $input ?>" type="text" name="supportContact" maxlength="200" value="<?= esc($value('supportContact')) ?>">
            </label>
            <label class="<?= $label ?>">Timezone
                <select class="<?= $input ?>" name="timezone">
                    <?php foreach ($genZones as $zone): ?>
                        <option value="<?= esc($zone) ?>" <?= $value('timezone') === $zone ? 'selected' : '' ?>><?= esc($zone) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
        </fieldset>

        <fieldset class="flex flex-col gap-3">
            <legend class="text-lg font-semibold text-brand">Maintenance</legend>
            <label class="flex items-center gap-2 text-sm text-gray-700">
                <input type="checkbox" name="maintenanceMode" value="1" <?= old('maintenanceMode', $genValues['maintenanceMode'] ? '1' : '') === '1' ? 'checked' : '' ?>>
                Maintenance mode: everyone except system administrators is turned away
            </label>
            <label class="<?= $label ?>">Message shown while it is on
                <textarea class="<?= $input ?>" name="maintenanceMessage" rows="2" maxlength="500" required><?= esc($value('maintenanceMessage')) ?></textarea>
            </label>
        </fieldset>

        <fieldset class="flex flex-col gap-3">
            <legend class="text-lg font-semibold text-brand">Announcement</legend>
            <label class="<?= $label ?>">A line shown at the top of every page <span class="text-xs text-gray-500">(leave empty for none; people can hide it for themselves)</span>
                <input class="<?= $input ?>" type="text" name="announcement" maxlength="500" value="<?= esc($value('announcement')) ?>">
            </label>
        </fieldset>

        <fieldset class="flex flex-col gap-3">
            <legend class="text-lg font-semibold text-brand">Subscriptions and backups</legend>
            <p class="text-xs text-gray-500">The default timeline for every institution that has no timeline of its own, in days after the end date, and how old the last good backup may get before an alert.</p>
            <label class="flex flex-wrap items-center gap-3 text-sm text-gray-700">Warn this many days before the end
                <input class="<?= $short ?>" type="number" name="expiryWarningDays" min="0" max="365" value="<?= esc($value('expiryWarningDays')) ?>" required>
            </label>
            <label class="flex flex-wrap items-center gap-3 text-sm text-gray-700">Grace period (full access) in days
                <input class="<?= $short ?>" type="number" name="expiryGraceDays" min="0" max="365" value="<?= esc($value('expiryGraceDays')) ?>" required>
            </label>
            <label class="flex flex-wrap items-center gap-3 text-sm text-gray-700">Read-only period in days
                <input class="<?= $short ?>" type="number" name="expiryReadOnlyDays" min="0" max="365" value="<?= esc($value('expiryReadOnlyDays')) ?>" required>
            </label>
            <label class="flex flex-wrap items-center gap-3 text-sm text-gray-700">Alert when the last good backup is older than (hours)
                <input class="<?= $short ?>" type="number" name="backupAlertHours" min="1" max="720" value="<?= esc($value('backupAlertHours')) ?>" required>
            </label>
        </fieldset>

        <div>
            <button type="submit" class="rounded bg-brand px-4 py-2 text-sm font-semibold text-white hover:bg-brand-dark">Save</button>
        </div>
    </form>
</div>

<?= $this->endSection() ?>
