<?php
/**
 * Invite a person. The account is made when they use the emailed link and
 * choose a password: nobody but them ever knows it.
 *
 * @var list<array<string, mixed>> $newInstitutions institutions that have finished onboarding
 * @var list<string>               $newRoles        the roles that can be given here
 * @var array<string, mixed>       $newValues       what was typed before a refusal
 */
use App\Services\Users\UserFilters;

$input = 'rounded border border-gray-300 bg-white px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand/55';
$label = 'flex flex-col gap-1 text-sm font-medium text-gray-700';
?>

<?= $this->extend('layouts/admin') ?>

<?= $this->section('content') ?>

<div class="mx-auto max-w-lg">
    <a class="text-sm font-semibold text-brand hover:underline" href="<?= site_url('admin/users') ?>">&larr; Users</a>

    <div class="mt-3 rounded-lg bg-white p-6 shadow">
        <h1 class="text-xl font-bold text-brand">Invite a person</h1>
        <p class="mt-1 text-sm text-gray-600">They get an emailed link, valid for a limited time and usable once, and choose their own password on it. The account is made then.</p>
        <p class="mt-1 text-xs text-gray-500">System administrators are not made here: they are created with <span class="font-mono">php spark admin:create</span>.</p>

        <?php if (session()->has('error')): ?>
            <div class="mt-4"><?= view('components/alert', ['tone' => 'danger', 'text' => (string) session('error')], ['saveData' => false]) ?></div>
        <?php endif; ?>

        <form method="post" action="<?= site_url('admin/users') ?>" class="mt-4 flex flex-col gap-4">
            <?= csrf_field() ?>

            <label class="<?= $label ?>">Institution
                <select class="<?= $input ?>" name="institution_id" required>
                    <option value="">Choose an institution</option>
                    <?php foreach ($newInstitutions as $institution): ?>
                        <option value="<?= esc((string) $institution['id']) ?>" <?= (string) $newValues['institution_id'] === (string) $institution['id'] ? 'selected' : '' ?>><?= esc($institution['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>

            <label class="<?= $label ?>">Role
                <select class="<?= $input ?>" name="role" required>
                    <option value="">Choose a role</option>
                    <?php foreach ($newRoles as $role): ?>
                        <option value="<?= esc($role) ?>" <?= $newValues['role'] === $role ? 'selected' : '' ?>><?= esc(UserFilters::ROLE_LABELS[$role] ?? $role) ?></option>
                    <?php endforeach; ?>
                </select>
                <span class="text-xs font-normal text-gray-500">A student takes one of the plan's seats.</span>
            </label>

            <div class="grid gap-4 sm:grid-cols-2">
                <label class="<?= $label ?>">First name
                    <input class="<?= $input ?>" type="text" name="first_name" value="<?= esc((string) $newValues['first_name']) ?>" maxlength="100" required>
                </label>
                <label class="<?= $label ?>">Last name
                    <input class="<?= $input ?>" type="text" name="last_name" value="<?= esc((string) $newValues['last_name']) ?>" maxlength="100" required>
                </label>
            </div>

            <label class="<?= $label ?>">Email address
                <input class="<?= $input ?>" type="email" name="email" value="<?= esc((string) $newValues['email']) ?>" maxlength="254" required>
            </label>

            <div class="flex gap-3">
                <button type="submit" class="rounded bg-brand px-4 py-2 text-sm font-semibold text-white hover:bg-brand-dark">Send invitation</button>
                <a class="px-3 py-2 text-sm font-semibold text-gray-600 hover:underline" href="<?= site_url('admin/users') ?>">Cancel</a>
            </div>
        </form>
    </div>
</div>

<?= $this->endSection() ?>
