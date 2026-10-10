<?php
/**
 * One person: who they are, whether they can sign in, their roles and
 * institutions, and what an administrator can do about it.
 *
 * @var array<string, mixed>       $person
 * @var list<array<string, mixed>> $personInstitutions    every institution, for the add-membership select
 * @var list<string>               $personAssignableRoles roles that can be ticked
 * @var bool                       $personIsSelf
 * @var array<string, mixed>       $personValues          name and email as shown in the form
 */
use App\Services\Users\UserFilters;

$input = 'rounded border border-gray-300 bg-white px-2 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-[#0040A6]/55';
$label = 'flex flex-col gap-1 text-xs font-medium text-gray-600';
$btn   = 'rounded border px-2.5 py-1 text-xs font-semibold';

$isAdmin     = in_array('admin', $person['roles'], true);
$deactivated = (int) $person['active'] === 0 || $person['deactivated_at'] !== null;
$name        = trim(($person['first_name'] ?? '') . ' ' . ($person['last_name'] ?? ''));
$memberOf    = array_column($person['institutions'], 'id');
?>

<?= $this->extend('layouts/admin') ?>

<?= $this->section('content') ?>

<a class="text-sm font-semibold text-[#0040A6] hover:underline" href="<?= site_url('admin/users') ?>">&larr; Users</a>

<div class="mt-2 flex flex-wrap items-center gap-3">
    <h1 class="text-2xl font-bold text-[#0040A6]"><?= esc($name !== '' ? $name : (string) $person['username']) ?></h1>
    <span class="rounded-full px-2 py-0.5 text-xs font-semibold <?= $deactivated ? 'bg-red-100 text-red-800' : 'bg-green-100 text-green-800' ?>" data-account-status="<?= $deactivated ? 'deactivated' : 'active' ?>"><?= $deactivated ? 'Deactivated' : 'Active' ?></span>
    <?php if ($personIsSelf): ?><span class="text-sm text-gray-500">(this is you)</span><?php endif; ?>
</div>
<p class="mt-1 text-sm text-gray-600"><?= esc((string) ($person['email'] ?? '')) ?> &middot; <span class="font-mono text-xs"><?= esc((string) $person['username']) ?></span></p>

<?php if (session()->has('message')): ?>
    <p class="mt-4 rounded bg-green-50 px-3 py-2 text-sm text-green-700" role="status"><?= esc((string) session('message')) ?></p>
<?php endif; ?>
<?php if (session()->has('error')): ?>
    <p class="mt-4 rounded bg-red-50 px-3 py-2 text-sm text-red-700" role="alert"><?= esc((string) session('error')) ?></p>
<?php endif; ?>

<div class="mt-6 grid gap-6 lg:grid-cols-2">

    <section class="rounded-lg bg-white p-6 shadow" data-section="details">
        <h2 class="text-lg font-semibold text-[#0040A6]">Details</h2>
        <form method="post" action="<?= site_url('admin/users/' . $person['id']) ?>" class="mt-3 grid gap-3 sm:grid-cols-2">
            <?= csrf_field() ?>
            <label class="<?= $label ?>">First name
                <input class="<?= $input ?>" type="text" name="first_name" value="<?= esc((string) $personValues['first_name']) ?>" maxlength="100" required>
            </label>
            <label class="<?= $label ?>">Last name
                <input class="<?= $input ?>" type="text" name="last_name" value="<?= esc((string) $personValues['last_name']) ?>" maxlength="100" required>
            </label>
            <label class="<?= $label ?> sm:col-span-2">Sign-in email
                <input class="<?= $input ?>" type="email" name="email" value="<?= esc((string) $personValues['email']) ?>" maxlength="254" required>
            </label>
            <div class="sm:col-span-2">
                <button type="submit" class="rounded bg-[#0040A6] px-4 py-1.5 text-sm font-semibold text-white hover:bg-[#002068]">Save</button>
                <span class="ml-2 text-xs text-gray-500">Needs your password.</span>
            </div>
        </form>
        <dl class="mt-4 grid grid-cols-[auto_1fr] gap-x-4 gap-y-1 text-sm">
            <dt class="text-gray-500">Last active</dt><dd><?= esc(substr((string) ($person['last_active'] ?? ''), 0, 16) ?: '—') ?></dd>
            <dt class="text-gray-500">Added</dt><dd><?= esc(substr((string) $person['created_at'], 0, 10)) ?></dd>
        </dl>
    </section>

    <section class="rounded-lg bg-white p-6 shadow" data-section="access">
        <h2 class="text-lg font-semibold text-[#0040A6]">Access</h2>
        <div class="mt-3 flex flex-col gap-3">
            <?php if ($deactivated): ?>
                <form method="post" action="<?= site_url('admin/users/' . $person['id'] . '/reactivate') ?>" class="flex flex-wrap items-center gap-3">
                    <?= csrf_field() ?>
                    <button type="submit" class="<?= $btn ?> border-green-600 text-green-700 hover:bg-green-50">Reactivate</button>
                    <span class="text-sm text-gray-600">They can sign in again.</span>
                </form>
            <?php else: ?>
                <form method="post" action="<?= site_url('admin/users/' . $person['id'] . '/deactivate') ?>" class="flex flex-wrap items-center gap-3">
                    <?= csrf_field() ?>
                    <button type="submit" class="<?= $btn ?> border-amber-500 text-amber-700 hover:bg-amber-50" <?= $personIsSelf ? 'disabled' : '' ?>>Deactivate</button>
                    <span class="text-sm text-gray-600"><?= $personIsSelf ? 'You cannot deactivate your own account.' : 'They are signed out at once and cannot sign in. Nothing is deleted.' ?></span>
                </form>
            <?php endif; ?>

            <form method="post" action="<?= site_url('admin/users/' . $person['id'] . '/sign-out') ?>" class="flex flex-wrap items-center gap-3">
                <?= csrf_field() ?>
                <button type="submit" class="<?= $btn ?> border-gray-300 text-gray-700 hover:bg-gray-50">Sign out everywhere</button>
                <span class="text-sm text-gray-600">Ends every session they have. They can sign in again.</span>
            </form>

            <form method="post" action="<?= site_url('admin/users/' . $person['id'] . '/reset-password') ?>" class="flex flex-wrap items-center gap-3">
                <?= csrf_field() ?>
                <button type="submit" class="<?= $btn ?> border-[#0040A6] text-[#0040A6] hover:bg-[#0040A6]/10" <?= $deactivated ? 'disabled' : '' ?>>Send a password reset link</button>
                <span class="text-sm text-gray-600">They choose the new password themselves. Needs your password.</span>
            </form>
        </div>
    </section>

    <section class="rounded-lg bg-white p-6 shadow" data-section="roles">
        <h2 class="text-lg font-semibold text-[#0040A6]">Roles</h2>

        <?php if ($isAdmin): ?>
            <p class="mt-3 text-sm text-gray-700">System administrator. This account's roles are not changed here: system administrators are created and managed with <span class="font-mono text-xs">php spark</span>.</p>
        <?php else: ?>
            <form method="post" action="<?= site_url('admin/users/' . $person['id'] . '/roles') ?>" class="mt-3 flex flex-col gap-2">
                <?= csrf_field() ?>
                <?php foreach ($personAssignableRoles as $role): ?>
                    <label class="flex items-center gap-2 text-sm text-gray-700">
                        <input type="checkbox" name="roles[]" value="<?= esc($role) ?>" <?= in_array($role, $person['roles'], true) ? 'checked' : '' ?>>
                        <?= esc(UserFilters::ROLE_LABELS[$role] ?? $role) ?>
                    </label>
                <?php endforeach; ?>
                <div class="mt-2">
                    <button type="submit" class="rounded bg-[#0040A6] px-4 py-1.5 text-sm font-semibold text-white hover:bg-[#002068]">Save roles</button>
                    <span class="ml-2 text-xs text-gray-500">At least one. Needs your password.</span>
                </div>
            </form>
        <?php endif; ?>
    </section>

    <section class="rounded-lg bg-white p-6 shadow" data-section="institutions">
        <h2 class="text-lg font-semibold text-[#0040A6]">Institutions</h2>

        <?php if ($person['institutions'] === []): ?>
            <p class="mt-3 text-sm text-gray-600"><?= $isAdmin ? 'System administrators belong to no institution.' : 'Not a member of any institution.' ?></p>
        <?php else: ?>
            <ul class="mt-3 flex flex-col gap-2">
                <?php foreach ($person['institutions'] as $institution): ?>
                    <li class="flex items-center justify-between gap-3 text-sm">
                        <a class="text-[#0040A6] hover:underline" href="<?= site_url('admin/institutions/' . $institution['id']) ?>"><?= esc($institution['name']) ?></a>
                        <form method="post" action="<?= site_url('admin/users/' . $person['id'] . '/memberships/' . $institution['id'] . '/remove') ?>">
                            <?= csrf_field() ?>
                            <button type="submit" class="<?= $btn ?> border-red-400 text-red-700 hover:bg-red-50">Remove</button>
                        </form>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>

        <?php if (! $isAdmin): ?>
            <form method="post" action="<?= site_url('admin/users/' . $person['id'] . '/memberships') ?>" class="mt-4 flex flex-wrap items-end gap-3">
                <?= csrf_field() ?>
                <label class="<?= $label ?>">Add to an institution
                    <select class="<?= $input ?>" name="institution_id" required>
                        <option value="">Choose an institution</option>
                        <?php foreach ($personInstitutions as $institution): ?>
                            <?php if (! in_array((int) $institution['id'], array_map('intval', $memberOf), true) && $institution['status'] !== 'pending'): ?>
                                <option value="<?= esc((string) $institution['id']) ?>"><?= esc($institution['name']) ?></option>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </select>
                </label>
                <button type="submit" class="<?= $btn ?> border-[#0040A6] text-[#0040A6] hover:bg-[#0040A6]/10">Add</button>
            </form>
            <p class="mt-1 text-xs text-gray-500">Changing institutions needs your password.</p>
        <?php endif; ?>
    </section>

</div>

<?= $this->endSection() ?>
