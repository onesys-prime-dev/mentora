<?php
/**
 * @var array<string, int|list<string>> $secValues
 * @var list<string>                          $secChoices
 * @var array<string, string>                 $secRoleLabels
 */
$input = 'w-28 rounded border border-gray-300 bg-white px-2 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-brand/55';
$value = static fn (string $key): string => (string) old($key, (string) $secValues[$key]);
$roles = (array) old('twoFactorRoles', $secValues['twoFactorRoles']);
?>

<?= $this->extend('layouts/admin') ?>

<?= $this->section('content') ?>

<div class="mx-auto max-w-2xl">
    <?= view('components/page-head', ['title' => 'Security settings', 'icon' => 'attention', 'backUrl' => site_url('admin/dashboard'), 'backLabel' => 'Admin home'], ['saveData' => false]) ?>
    <p class="mt-1 text-sm text-gray-600">Apply to the whole platform. Changing them needs your password and is recorded in the audit log.</p>

    <?php if (session()->has('message')): ?>
        <div class="mt-4"><?= view('components/alert', ['tone' => 'success', 'text' => (string) session('message')], ['saveData' => false]) ?></div>
    <?php endif; ?>
    <?php if (session()->has('error')): ?>
        <div class="mt-4"><?= view('components/alert', ['tone' => 'danger', 'text' => (string) session('error')], ['saveData' => false]) ?></div>
    <?php endif; ?>

    <form method="post" action="<?= site_url('admin/settings/security') ?>" class="mt-6 flex flex-col gap-6 rounded-lg bg-white p-6 shadow">
        <?= csrf_field() ?>

        <fieldset>
            <legend class="text-lg font-semibold text-brand">Passwords</legend>
            <label class="mt-3 flex flex-wrap items-center gap-3 text-sm text-gray-700">Shortest password
                <input class="<?= $input ?>" type="number" name="passwordMinLength" min="8" max="64" value="<?= esc($value('passwordMinLength')) ?>" required>
                <span class="text-xs text-gray-500">characters (8 to 64). Common passwords and ones containing the person's name or email are always refused.</span>
            </label>
        </fieldset>

        <fieldset>
            <legend class="text-lg font-semibold text-brand">Sessions</legend>
            <label class="mt-3 flex flex-wrap items-center gap-3 text-sm text-gray-700">Sign out after
                <input class="<?= $input ?>" type="number" name="sessionTimeoutMinutes" min="5" max="120" value="<?= esc($value('sessionTimeoutMinutes')) ?>" required>
                <span class="text-xs text-gray-500">minutes of inactivity (5 to 120).</span>
            </label>
        </fieldset>

        <fieldset>
            <legend class="text-lg font-semibold text-brand">Lockout</legend>
            <label class="mt-3 flex flex-wrap items-center gap-3 text-sm text-gray-700">Lock sign-in after
                <input class="<?= $input ?>" type="number" name="lockoutAttempts" min="0" max="50" value="<?= esc($value('lockoutAttempts')) ?>" required>
                <span class="text-xs text-gray-500">failed attempts in a row. 0 turns the lockout off.</span>
            </label>
            <label class="mt-3 flex flex-wrap items-center gap-3 text-sm text-gray-700">Locked for
                <input class="<?= $input ?>" type="number" name="lockoutMinutes" min="1" max="1440" value="<?= esc($value('lockoutMinutes')) ?>" required>
                <span class="text-xs text-gray-500">minutes.</span>
            </label>
        </fieldset>

        <fieldset>
            <legend class="text-lg font-semibold text-brand">Emailed sign-in code</legend>
            <p class="mt-1 text-xs text-gray-500">System administrators always need one. Turning it on for another role needs working email (<span class="font-mono">php spark mail:test</span>): without it those people cannot sign in.</p>
            <div class="mt-3 flex flex-col gap-2">
                <label class="flex items-center gap-2 text-sm text-gray-500"><input type="checkbox" checked disabled> <?= esc($secRoleLabels['admin']) ?> (always)</label>
                <?php foreach ($secChoices as $role): ?>
                    <label class="flex items-center gap-2 text-sm text-gray-700">
                        <input type="checkbox" name="twoFactorRoles[]" value="<?= esc($role) ?>" <?= in_array($role, $roles, true) ? 'checked' : '' ?>>
                        <?= esc($secRoleLabels[$role] ?? $role) ?>
                    </label>
                <?php endforeach; ?>
            </div>
        </fieldset>

        <div>
            <button type="submit" class="rounded bg-brand px-4 py-2 text-sm font-semibold text-white hover:bg-brand-dark">Save</button>
        </div>
    </form>
</div>

<?= $this->endSection() ?>
