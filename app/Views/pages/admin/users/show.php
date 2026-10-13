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
 * @var bool                       $personSisManaged      the SIS decides their name and email
 */
use App\Services\Users\UserFilters;

$input = 'rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm focus:border-brand/60 focus:outline-none focus:ring-2 focus:ring-brand/40';
$label = 'flex flex-col gap-1 text-xs font-medium text-[#646464]';
$btn   = 'rounded-full border px-3 py-1 text-xs font-semibold transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/50';

$isAdmin     = in_array('admin', $person['roles'], true);
$deactivated = (int) $person['active'] === 0 || $person['deactivated_at'] !== null;
$name        = trim(($person['first_name'] ?? '') . ' ' . ($person['last_name'] ?? ''));
$memberOf    = array_column($person['institutions'], 'id');
?>

<?= $this->extend('layouts/admin') ?>

<?= $this->section('content') ?>

<?= view('components/page-head', [
    'title'     => $name !== '' ? $name : (string) $person['username'],
    'icon'      => 'user',
    'backUrl'   => site_url('admin/users'),
    'backLabel' => 'Users',
    'actions'   => '<span class="rounded-full px-2 py-0.5 text-xs font-semibold ' . ($deactivated ? 'bg-[#FDEDEC] text-[#C33939]' : 'bg-[#E8F5EC] text-[#2B7549]') . '" data-account-status="' . ($deactivated ? 'deactivated' : 'active') . '">' . ($deactivated ? 'Deactivated' : 'Active') . '</span>'
        . ($personIsSelf ? '<span class="text-sm text-[#646464]">(this is you)</span>' : ''),
], ['saveData' => false]) ?>
<p class="mt-1 text-sm text-[#646464]"><?= esc((string) ($person['email'] ?? '')) ?> &middot; <span class="font-mono text-xs"><?= esc((string) $person['username']) ?></span></p>

<?php if (session()->has('message')): ?>
    <div class="mt-4"><?= view('components/alert', ['tone' => 'success', 'text' => (string) session('message')], ['saveData' => false]) ?></div>
<?php endif; ?>
<?php if (session()->has('error')): ?>
    <div class="mt-4"><?= view('components/alert', ['tone' => 'danger', 'text' => (string) session('error')], ['saveData' => false]) ?></div>
<?php endif; ?>

<div class="mt-6 grid gap-6 lg:grid-cols-2">

    <section class="rounded-2xl bg-white p-6 shadow-sm" data-section="details">
        <h2 class="text-lg font-semibold text-brand">Details</h2>
        <?php if ($personSisManaged): ?>
            <p class="mt-2 rounded bg-amber-50 px-3 py-2 text-sm text-amber-800" data-sis-managed>This person comes from the school's student information system, which decides their name and email. Change them there; the next sync brings it here.</p>
        <?php endif; ?>
        <form data-dialog-form method="post" action="<?= site_url('admin/users/' . $person['id']) ?>" class="mt-3 grid gap-3 sm:grid-cols-2">
            <?= csrf_field() ?>
            <label class="<?= $label ?>">First name
                <input class="<?= $input ?>" type="text" name="first_name" value="<?= esc((string) $personValues['first_name']) ?>" maxlength="100" required <?= $personSisManaged ? 'readonly' : '' ?>>
            </label>
            <label class="<?= $label ?>">Last name
                <input class="<?= $input ?>" type="text" name="last_name" value="<?= esc((string) $personValues['last_name']) ?>" maxlength="100" required <?= $personSisManaged ? 'readonly' : '' ?>>
            </label>
            <label class="<?= $label ?> sm:col-span-2">Sign-in email
                <input class="<?= $input ?>" type="email" name="email" value="<?= esc((string) $personValues['email']) ?>" maxlength="254" required <?= $personSisManaged ? 'readonly' : '' ?>>
            </label>
            <?php if (! $personSisManaged): ?>
                <div class="sm:col-span-2">
                    <button type="submit" class="rounded-full bg-brand px-5 py-2 text-sm font-semibold text-white transition hover:bg-brand-dark focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/50">Save</button>
                    <span class="ml-2 text-xs text-[#646464]">Needs your password.</span>
                </div>
            <?php endif; ?>
        </form>
        <dl class="mt-4 grid grid-cols-[auto_1fr] gap-x-4 gap-y-1 text-sm">
            <dt class="text-[#646464]">Last active</dt><dd><?= esc(substr((string) ($person['last_active'] ?? ''), 0, 16) ?: '—') ?></dd>
            <dt class="text-[#646464]">Added</dt><dd><?= esc(substr((string) $person['created_at'], 0, 10)) ?></dd>
        </dl>
    </section>

    <section class="rounded-2xl bg-white p-6 shadow-sm" data-section="access">
        <h2 class="text-lg font-semibold text-brand">Access</h2>
        <div class="mt-3 flex flex-col gap-3">
            <?php if ($deactivated): ?>
                <form method="post" action="<?= site_url('admin/users/' . $person['id'] . '/reactivate') ?>" class="flex flex-wrap items-center gap-3">
                    <?= csrf_field() ?>
                    <button type="submit" class="<?= $btn ?> border-[#2B7549] bg-white text-[#2B7549] hover:bg-[#E8F5EC]">Reactivate</button>
                    <span class="text-sm text-[#646464]">They can sign in again.</span>
                </form>
            <?php else: ?>
                <form method="post" action="<?= site_url('admin/users/' . $person['id'] . '/deactivate') ?>" class="flex flex-wrap items-center gap-3">
                    <?= csrf_field() ?>
                    <button type="submit" class="<?= $btn ?> border-[#946200] bg-white text-[#946200] hover:bg-[#FFF1D6]" <?= $personIsSelf ? 'disabled' : '' ?>>Deactivate</button>
                    <span class="text-sm text-[#646464]"><?= $personIsSelf ? 'You cannot deactivate your own account.' : 'They are signed out at once and cannot sign in. Nothing is deleted.' ?></span>
                </form>
            <?php endif; ?>

            <form method="post" action="<?= site_url('admin/users/' . $person['id'] . '/sign-out') ?>" class="flex flex-wrap items-center gap-3">
                <?= csrf_field() ?>
                <button type="submit" class="<?= $btn ?> border-gray-300 bg-white text-[#3D3D3D] hover:bg-gray-50">Sign out everywhere</button>
                <span class="text-sm text-[#646464]">Ends every session they have. They can sign in again.</span>
            </form>

            <form data-dialog-form method="post" action="<?= site_url('admin/users/' . $person['id'] . '/reset-password') ?>" class="flex flex-wrap items-center gap-3">
                <?= csrf_field() ?>
                <button type="submit" class="<?= $btn ?> border-brand bg-white text-brand hover:bg-brand-tint" <?= $deactivated ? 'disabled' : '' ?>>Send a password reset link</button>
                <span class="text-sm text-[#646464]">They choose the new password themselves. Needs your password.</span>
            </form>
        </div>
    </section>

    <?php if (! $isAdmin && ! $personIsSelf && ! $deactivated): ?>
        <section class="rounded-2xl bg-white p-6 shadow-sm lg:col-span-2" data-section="view-as">
            <h2 class="text-lg font-semibold text-brand">View as this person</h2>
            <p class="mt-1 text-sm text-[#646464]">See the platform exactly as they do. It is <strong>view only</strong>: nothing can be changed, it ends by itself after the time limit, and it is kept in the audit log with your reason. Needs your password.</p>
            <form data-dialog-form method="post" action="<?= site_url('admin/users/' . $person['id'] . '/impersonate') ?>" class="mt-3 flex flex-wrap items-end gap-3">
                <?= csrf_field() ?>
                <label class="<?= $label ?> min-w-[18rem] flex-1">Why are you viewing as them?
                    <input class="<?= $input ?>" type="text" name="reason" maxlength="500" minlength="5" required placeholder="For example: support ticket 1234, grades not showing">
                </label>
                <button type="submit" class="rounded-full bg-[#946200] px-5 py-2 text-sm font-semibold text-white transition hover:bg-[#6F4A00] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#946200]/50">View as <?= esc($name !== '' ? $name : (string) $person['username']) ?></button>
            </form>
        </section>
    <?php endif; ?>

    <section class="rounded-2xl bg-white p-6 shadow-sm" data-section="roles">
        <h2 class="text-lg font-semibold text-brand">Roles</h2>

        <?php if ($isAdmin): ?>
            <p class="mt-3 text-sm text-gray-700">System administrator. This account's roles are not changed here: system administrators are created and managed with <span class="font-mono text-xs">php spark</span>.</p>
        <?php else: ?>
            <form data-dialog-form method="post" action="<?= site_url('admin/users/' . $person['id'] . '/roles') ?>" class="mt-3 flex flex-col gap-2">
                <?= csrf_field() ?>
                <?php foreach ($personAssignableRoles as $role): ?>
                    <label class="flex items-center gap-2 text-sm text-gray-700">
                        <input type="checkbox" name="roles[]" value="<?= esc($role) ?>" <?= in_array($role, $person['roles'], true) ? 'checked' : '' ?>>
                        <?= esc(UserFilters::ROLE_LABELS[$role] ?? $role) ?>
                    </label>
                <?php endforeach; ?>
                <div class="mt-2">
                    <button type="submit" class="rounded-full bg-brand px-5 py-2 text-sm font-semibold text-white transition hover:bg-brand-dark focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/50">Save roles</button>
                    <span class="ml-2 text-xs text-[#646464]">At least one. Needs your password.</span>
                </div>
            </form>
        <?php endif; ?>
    </section>

    <section class="rounded-2xl bg-white p-6 shadow-sm" data-section="institutions">
        <h2 class="text-lg font-semibold text-brand">Institutions</h2>

        <?php if ($person['institutions'] === []): ?>
            <p class="mt-3 text-sm text-[#646464]"><?= $isAdmin ? 'System administrators belong to no institution.' : 'Not a member of any institution.' ?></p>
        <?php else: ?>
            <ul class="mt-3 flex flex-col gap-2">
                <?php foreach ($person['institutions'] as $institution): ?>
                    <li class="flex items-center justify-between gap-3 text-sm">
                        <a class="text-brand hover:underline" href="<?= site_url('admin/institutions/' . $institution['id']) ?>"><?= esc($institution['name']) ?></a>
                        <form data-dialog-form method="post" action="<?= site_url('admin/users/' . $person['id'] . '/memberships/' . $institution['id'] . '/remove') ?>">
                            <?= csrf_field() ?>
                            <button type="submit" class="<?= $btn ?> border-[#C33939] bg-white text-[#C33939] hover:bg-[#FDEDEC]">Remove</button>
                        </form>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>

        <?php if (! $isAdmin): ?>
            <form data-dialog-form method="post" action="<?= site_url('admin/users/' . $person['id'] . '/memberships') ?>" class="mt-4 flex flex-wrap items-end gap-3">
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
                <button type="submit" class="<?= $btn ?> border-brand bg-white text-brand hover:bg-brand-tint">Add</button>
            </form>
            <p class="mt-1 text-xs text-[#646464]">Changing institutions needs your password.</p>
        <?php endif; ?>
    </section>

</div>

<?= $this->endSection() ?>
