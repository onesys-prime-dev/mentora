<?php
/**
 * @var array<string, int|list<string>> $secValues
 * @var list<string>                          $secChoices
 * @var array<string, string>                 $secRoleLabels
 */
$value = static fn (string $key): string => (string) old($key, (string) $secValues[$key]);
$roles = (array) old('twoFactorRoles', $secValues['twoFactorRoles']);

$field   = static fn (array $spec): string => view('components/form-field', $spec, ['saveData' => false]);
$primary = 'rounded-full bg-brand px-5 py-2 text-sm font-semibold text-white transition hover:bg-brand-dark focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/50';
$legend  = 'text-lg font-bold text-brand';
$tick    = 'h-4 w-4 rounded border-gray-300 text-brand focus:ring-brand/40';
?>

<?= $this->extend('layouts/admin') ?>

<?= $this->section('content') ?>

<div class="mx-auto max-w-2xl">
    <?= view('components/page-head', [
        'title'       => 'Security settings',
        'icon'        => 'attention',
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

    <form method="post" action="<?= site_url('admin/settings/security') ?>" data-dialog-form class="mt-6 flex flex-col gap-6 rounded-2xl bg-white p-6 shadow-sm">
        <?= csrf_field() ?>

        <fieldset class="flex flex-col gap-3">
            <legend class="<?= $legend ?>">Passwords</legend>
            <?= $field(['name' => 'passwordMinLength', 'label' => 'Shortest password (characters)', 'type' => 'number', 'value' => $value('passwordMinLength'), 'required' => true, 'attrs' => 'min="8" max="64"', 'help' => '8 to 64. Common passwords and ones containing the person\'s name or email are always refused.']) ?>
        </fieldset>

        <fieldset class="flex flex-col gap-3">
            <legend class="<?= $legend ?>">Sessions</legend>
            <?= $field(['name' => 'sessionTimeoutMinutes', 'label' => 'Sign out after (minutes of inactivity)', 'type' => 'number', 'value' => $value('sessionTimeoutMinutes'), 'required' => true, 'attrs' => 'min="5" max="120"', 'help' => '5 to 120.']) ?>
        </fieldset>

        <fieldset class="flex flex-col gap-3">
            <legend class="<?= $legend ?>">Lockout</legend>
            <?= $field(['name' => 'lockoutAttempts', 'label' => 'Lock sign-in after (failed attempts in a row)', 'type' => 'number', 'value' => $value('lockoutAttempts'), 'required' => true, 'attrs' => 'min="0" max="50"', 'help' => '0 turns the lockout off.']) ?>
            <?= $field(['name' => 'lockoutMinutes', 'label' => 'Locked for (minutes)', 'type' => 'number', 'value' => $value('lockoutMinutes'), 'required' => true, 'attrs' => 'min="1" max="1440"']) ?>
        </fieldset>

        <fieldset class="flex flex-col gap-2">
            <legend class="<?= $legend ?>">Emailed sign-in code</legend>
            <p class="text-xs text-[#646464]">System administrators always need one. Turning it on for another role needs working email (<span class="font-mono">php spark mail:test</span>): without it those people cannot sign in.</p>

            <label class="flex items-center gap-2 text-sm text-[#646464]"><input type="checkbox" class="<?= $tick ?>" checked disabled> <?= esc($secRoleLabels['admin']) ?> (always)</label>
            <?php foreach ($secChoices as $role): ?>
                <label class="flex items-center gap-2 text-sm text-[#1A1C1E]">
                    <input type="checkbox" class="<?= $tick ?>" name="twoFactorRoles[]" value="<?= esc($role) ?>" <?= in_array($role, $roles, true) ? 'checked' : '' ?>>
                    <?= esc($secRoleLabels[$role] ?? $role) ?>
                </label>
            <?php endforeach; ?>
        </fieldset>

        <p class="text-xs font-medium text-[#C33939]" data-form-error hidden></p>

        <div class="flex justify-end">
            <button type="submit" class="<?= $primary ?>">Save</button>
        </div>
    </form>
</div>

<?= $this->endSection() ?>
