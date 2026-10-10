<?php
/**
 * An institution's people and the invitations to administer it.
 *
 * @var array<string, mixed>       $peopleInstitution
 * @var array{total: int, perRole: array<string, int>, rows: list<array<string, mixed>>} $peopleMembers
 * @var list<array<string, mixed>> $peopleInvitations newest first, each with `status`
 * @var array<string, mixed>       $peopleValues      first_name, last_name, email as typed
 */
$input = 'rounded border border-gray-300 bg-white px-2 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-[#0040A6]/55';
$label = 'flex flex-col gap-1 text-xs font-medium text-gray-600';
$th    = 'px-3 py-2 text-left text-xs font-semibold uppercase tracking-wide text-gray-600';
$btn   = 'rounded border px-2.5 py-1 text-xs font-semibold';

$invitationBadge = [
    'pending'  => ['Waiting for them', 'bg-amber-100 text-amber-800'],
    'accepted' => ['Accepted', 'bg-green-100 text-green-800'],
    'revoked'  => ['Replaced', 'bg-gray-100 text-gray-700'],
    'expired'  => ['Expired', 'bg-red-100 text-red-800'],
];

$roleNames = [
    'admin' => 'System administrator', 'institutional_admin' => 'Institutional administrator', 'chairperson' => 'Chairperson',
    'instructor' => 'Instructor', 'student' => 'Student', 'parent' => 'Parent',
];

$isPending = $peopleInstitution['status'] === 'pending';
$isDeleted = $peopleInstitution['deleted_at'] !== null;
?>

<?= $this->extend('layouts/admin') ?>

<?= $this->section('content') ?>

<a class="text-sm font-semibold text-[#0040A6] hover:underline" href="<?= site_url('admin/institutions') ?>">&larr; Institutions</a>

<h1 class="mt-2 text-2xl font-bold text-[#0040A6]">Users</h1>
<p class="mt-1 text-sm text-gray-600"><?= esc($peopleInstitution['name']) ?> <span class="font-mono text-xs">(<?= esc($peopleInstitution['code']) ?>)</span></p>

<?= view('pages/admin/partials/institution-tabs', ['tabInstitution' => $peopleInstitution, 'tabActive' => 'users']) ?>

<?php if (session()->has('message')): ?>
    <p class="mt-4 rounded bg-green-50 px-3 py-2 text-sm text-green-700" role="status"><?= esc((string) session('message')) ?></p>
<?php endif; ?>
<?php if (session()->has('error')): ?>
    <p class="mt-4 rounded bg-red-50 px-3 py-2 text-sm text-red-700" role="alert"><?= esc((string) session('error')) ?></p>
<?php endif; ?>

<section class="mt-6 rounded-lg bg-white p-6 shadow" data-invitations>
    <h2 class="text-lg font-semibold text-[#0040A6]">Administrator invitations</h2>
    <p class="mt-1 text-sm text-gray-600">An invitation is an emailed link that works once. The person sets their own password and the account is made then. Only the newest invitation works: sending another cancels the one before it.</p>

    <?php if ($peopleInvitations === []): ?>
        <p class="mt-4 text-sm text-gray-500">No invitations yet.</p>
    <?php else: ?>
        <div class="mt-4 overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200 text-sm">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="<?= $th ?>">Person</th>
                        <th class="<?= $th ?>">Status</th>
                        <th class="<?= $th ?>">Expires</th>
                        <th class="<?= $th ?>">Email</th>
                        <th class="<?= $th ?>">Invited by</th>
                        <th class="<?= $th ?>"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    <?php foreach ($peopleInvitations as $invitation): ?>
                        <?php [$badgeText, $badgeClass] = $invitationBadge[$invitation['status']] ?? [$invitation['status'], 'bg-gray-100 text-gray-700']; ?>
                        <tr data-invitation="<?= esc($invitation['status']) ?>">
                            <td class="px-3 py-2"><?= esc($invitation['first_name'] . ' ' . $invitation['last_name']) ?><span class="block text-xs text-gray-500"><?= esc($invitation['email']) ?></span></td>
                            <td class="px-3 py-2"><span class="rounded-full px-2 py-0.5 text-xs font-semibold <?= $badgeClass ?>"><?= esc($badgeText) ?></span></td>
                            <td class="whitespace-nowrap px-3 py-2 text-gray-600"><?= esc(substr((string) $invitation['expires_at'], 0, 16)) ?></td>
                            <td class="px-3 py-2 text-xs text-gray-600"><?= $invitation['sent_at'] === null ? '<span class="font-semibold text-red-700">Not sent</span>' : 'Sent ' . esc(substr((string) $invitation['sent_at'], 0, 16)) ?></td>
                            <td class="px-3 py-2 text-xs text-gray-600"><?= esc($invitation['invited_by_label'] ?? '—') ?></td>
                            <td class="px-3 py-2">
                                <?php if ($invitation['status'] !== 'accepted' && ! $isDeleted): ?>
                                    <form method="post" action="<?= site_url('admin/institutions/' . $peopleInstitution['id'] . '/invitations/' . $invitation['id'] . '/resend') ?>">
                                        <?= csrf_field() ?>
                                        <button type="submit" class="<?= $btn ?> border-[#0040A6] text-[#0040A6] hover:bg-[#0040A6]/10">Resend invitation</button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>

    <?php if ($isPending): ?>
        <p class="mt-4 rounded bg-amber-50 px-3 py-2 text-sm text-amber-800">The administrator is invited as the last step of onboarding. <a class="font-semibold underline" href="<?= site_url('admin/institutions/' . $peopleInstitution['id'] . '/onboarding') ?>">Continue onboarding</a></p>
    <?php elseif (! $isDeleted): ?>
        <details class="mt-4">
            <summary class="<?= $btn ?> inline-block cursor-pointer border-gray-300 text-gray-700 hover:bg-gray-50">Invite an administrator</summary>
            <form method="post" action="<?= site_url('admin/institutions/' . $peopleInstitution['id'] . '/invitations') ?>" class="mt-3 grid max-w-2xl gap-3 sm:grid-cols-2">
                <?= csrf_field() ?>
                <label class="<?= $label ?>">First name
                    <input class="<?= $input ?>" type="text" name="first_name" value="<?= esc((string) $peopleValues['first_name']) ?>" maxlength="100" required>
                </label>
                <label class="<?= $label ?>">Last name
                    <input class="<?= $input ?>" type="text" name="last_name" value="<?= esc((string) $peopleValues['last_name']) ?>" maxlength="100" required>
                </label>
                <label class="<?= $label ?> sm:col-span-2">Email address
                    <input class="<?= $input ?>" type="email" name="email" value="<?= esc((string) $peopleValues['email']) ?>" maxlength="254" required>
                </label>
                <div class="sm:col-span-2">
                    <button type="submit" class="rounded bg-[#0040A6] px-4 py-1.5 text-sm font-semibold text-white hover:bg-[#002068]">Send invitation</button>
                </div>
            </form>
        </details>
    <?php endif; ?>
</section>

<section class="mt-6 rounded-lg bg-white p-6 shadow" data-members>
    <h2 class="text-lg font-semibold text-[#0040A6]">People</h2>
    <p class="mt-1 text-sm text-gray-600">
        <?= esc((string) $peopleMembers['total']) ?> <?= $peopleMembers['total'] === 1 ? 'person' : 'people' ?>
        <?php if ($peopleMembers['perRole'] !== []): ?>
            (<?= esc(implode(', ', array_map(static fn (string $role, int $count): string => $count . ' ' . strtolower($roleNames[$role] ?? $role), array_keys($peopleMembers['perRole']), $peopleMembers['perRole']))) ?>)
        <?php endif; ?>
    </p>

    <?php if ($peopleMembers['rows'] === []): ?>
        <p class="mt-4 text-sm text-gray-500">Nobody belongs to this institution yet.</p>
    <?php else: ?>
        <div class="mt-4 overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200 text-sm">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="<?= $th ?>">Name</th>
                        <th class="<?= $th ?>">Email</th>
                        <th class="<?= $th ?>">Role</th>
                        <th class="<?= $th ?>">Account</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    <?php foreach ($peopleMembers['rows'] as $member): ?>
                        <tr>
                            <td class="px-3 py-2"><?= esc(trim(($member['first_name'] ?? '') . ' ' . ($member['last_name'] ?? '')) ?: $member['username']) ?></td>
                            <td class="px-3 py-2 text-gray-600"><?= esc($member['email'] ?? '—') ?></td>
                            <td class="px-3 py-2"><?= esc(implode(', ', array_map(static fn (string $group): string => $roleNames[$group] ?? $group, $member['groups']))) ?></td>
                            <td class="px-3 py-2"><?= (int) $member['active'] === 1 ? 'Active' : '<span class="text-red-700">Deactivated</span>' ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php if ($peopleMembers['total'] > count($peopleMembers['rows'])): ?>
            <p class="mt-2 text-xs text-gray-500">Showing the first <?= esc((string) count($peopleMembers['rows'])) ?> of <?= esc((string) $peopleMembers['total']) ?>.</p>
        <?php endif; ?>
    <?php endif; ?>
</section>

<?= $this->endSection() ?>
