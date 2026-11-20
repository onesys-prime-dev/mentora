<?php
/**
 * An institution's people and the invitations to administer it.
 *
 * @var array<string, mixed>       $peopleInstitution
 * @var array{total: int, perRole: array<string, int>, rows: list<array<string, mixed>>} $peopleMembers
 * @var list<array<string, mixed>> $peopleInvitations newest first, each with `status`
 * @var array<string, mixed>       $peopleValues      first_name, last_name, email as typed
 */
$btn   = 'inline-flex items-center justify-center pointer-coarse:min-h-11 rounded-full border px-3 py-1 text-xs font-semibold transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/50';

$invitationBadge = [
    'pending'  => ['Waiting for them', 'bg-[#FFF1D6] text-[#946200]'],
    'accepted' => ['Accepted', 'bg-[#E8F5EC] text-[#2B7549]'],
    'revoked'  => ['Replaced', 'bg-gray-100 text-gray-700'],
    'expired'  => ['Expired', 'bg-[#FDEDEC] text-[#C33939]'],
];

$roleNames = [
    'admin' => 'System administrator', 'institutional_admin' => 'Institutional administrator', 'dean' => 'Dean', 'chairperson' => 'Chairperson',
    'instructor' => 'Instructor', 'student' => 'Student', 'parent' => 'Parent',
];

$invitationTone = ['pending' => 'warning', 'accepted' => 'success', 'revoked' => 'neutral', 'expired' => 'danger'];

$inviteRows = [];

foreach ($peopleInvitations as $invitation) {
    [$badgeText] = $invitationBadge[$invitation['status']] ?? [$invitation['status']];
    $base        = site_url('admin/institutions/' . $peopleInstitution['id'] . '/invitations/' . $invitation['id']);
    $actions     = '';

    if ($invitation['status'] !== 'accepted' && $peopleInstitution['deleted_at'] === null) {
        $actions = '<div class="flex flex-wrap items-start justify-end gap-2 max-md:justify-start">'
            . '<form method="post" action="' . $base . '/resend">' . csrf_field()
            . '<button type="submit" class="' . $btn . ' border-brand bg-white text-brand hover:bg-brand-tint">Resend invitation</button></form>'
            . ($invitation['status'] === 'pending'
                ? '<form method="post" action="' . $base . '/revoke">' . csrf_field()
                    . '<button type="submit" class="' . $btn . ' border-[#C33939] bg-white text-[#C33939] hover:bg-[#FDEDEC]">Cancel</button></form>'
                : '')
            . '</div>';
    }

    $inviteRows[] = [
        'id'      => (string) $invitation['id'],
        'label'   => $invitation['first_name'] . ' ' . $invitation['last_name'],
        'cells'   => [
            'person'     => ['html' => esc($invitation['first_name'] . ' ' . $invitation['last_name']) . '<span class="block text-xs text-[#646464]">' . esc($invitation['email']) . '</span>'],
            'role'       => ['html' => '<span>' . esc($roleNames[$invitation['role']] ?? $invitation['role']) . '</span>'],
            'status'     => ['html' => '<span data-invitation="' . esc($invitation['status']) . '">' . view('components/status-badge', ['label' => $badgeText, 'tone' => $invitationTone[$invitation['status']] ?? 'neutral'], ['saveData' => false]) . '</span>'],
            'expires'    => substr((string) $invitation['expires_at'], 0, 16),
            'email'      => ['html' => $invitation['sent_at'] === null ? '<span class="font-semibold text-red-700">Not sent</span>' : 'Sent ' . esc(substr((string) $invitation['sent_at'], 0, 16))],
            'invited_by' => (string) ($invitation['invited_by_label'] ?? '—'),
        ],
        'actions' => $actions,
    ];
}

$memberRows = [];

foreach ($peopleMembers['rows'] as $member) {
    $active       = (int) $member['active'] === 1;
    $memberRows[] = [
        'id'    => (string) ($member['id'] ?? $member['username']),
        'label' => trim(($member['first_name'] ?? '') . ' ' . ($member['last_name'] ?? '')) ?: $member['username'],
        'cells' => [
            'name'    => trim(($member['first_name'] ?? '') . ' ' . ($member['last_name'] ?? '')) ?: $member['username'],
            'email'   => (string) ($member['email'] ?? '—'),
            'role'    => implode(', ', array_map(static fn (string $group): string => $roleNames[$group] ?? $group, $member['groups'])),
            'account' => ['html' => view('components/status-badge', ['label' => $active ? 'Active' : 'Deactivated', 'tone' => $active ? 'success' : 'danger'], ['saveData' => false])],
        ],
    ];
}

$isPending = $peopleInstitution['status'] === 'pending';
$isDeleted = $peopleInstitution['deleted_at'] !== null;
?>

<?= $this->extend('layouts/admin') ?>

<?= $this->section('content') ?>

<div class="rounded-3xl bg-white p-5 shadow-sm sm:p-8" data-page-panel tabindex="0" role="region" aria-label="Page content">

<?= view('components/page-head', ['title' => 'Users', 'icon' => 'user', 'backUrl' => site_url('admin/institutions'), 'backLabel' => 'Institutions'], ['saveData' => false]) ?>
<p class="mt-1 text-sm text-[#646464]"><?= esc($peopleInstitution['name']) ?> <span class="font-mono text-xs">(<?= esc($peopleInstitution['code']) ?>)</span></p>

<?= view('pages/admin/partials/institution-tabs', ['tabInstitution' => $peopleInstitution, 'tabActive' => 'users']) ?>

<?php if (session()->has('message')): ?>
    <div class="mt-4"><?= view('components/alert', ['tone' => 'success', 'text' => (string) session('message')], ['saveData' => false]) ?></div>
<?php endif; ?>
<?php if (session()->has('error')): ?>
    <div class="mt-4"><?= view('components/alert', ['tone' => 'danger', 'text' => (string) session('error')], ['saveData' => false]) ?></div>
<?php endif; ?>

<section class="mt-6 rounded-2xl bg-white p-6 shadow-sm" data-invitations>
    <h2 class="text-lg font-semibold text-brand">Administrator invitations</h2>
    <p class="mt-1 text-sm text-[#646464]">An invitation is an emailed link that works once. The person sets their own password and the account is made then. Only the newest invitation works: sending another cancels the one before it.</p>

    <?php if ($peopleInvitations === []): ?>
        <p class="mt-4 text-sm text-[#646464]">No invitations yet.</p>
    <?php else: ?>
        <div class="mt-4">
            <?= view('components/data-table', [
                'caption' => 'Administrator invitations',
                'columns' => [
                    ['key' => 'person', 'label' => 'Person'],
                    ['key' => 'role', 'label' => 'Role'],
                    ['key' => 'status', 'label' => 'Status'],
                    ['key' => 'expires', 'label' => 'Expires'],
                    ['key' => 'email', 'label' => 'Email'],
                    ['key' => 'invited_by', 'label' => 'Invited by', 'priority' => 2],
                ],
                'rows'    => $inviteRows,
                'id'      => 'invitations',
            ], ['saveData' => false]) ?>
        </div>
    <?php endif; ?>

    <?php if ($isPending): ?>
        <div class="mt-4">
            <?= view('components/alert', ['tone' => 'warning', 'text' => 'The administrator is invited as the last step of onboarding.'], ['saveData' => false]) ?>
            <p class="mt-2 text-sm"><a class="font-semibold text-brand underline" href="<?= site_url('admin/institutions/' . $peopleInstitution['id'] . '/onboarding') ?>">Continue onboarding</a></p>
        </div>
    <?php elseif (! $isDeleted): ?>
        <details class="mt-4">
            <summary class="<?= $btn ?> cursor-pointer border-gray-300 bg-white text-[#3D3D3D] hover:bg-gray-50">Invite an administrator</summary>
            <form method="post" action="<?= site_url('admin/institutions/' . $peopleInstitution['id'] . '/invitations') ?>" class="mt-3 grid max-w-2xl grid-cols-1 gap-3 md:grid-cols-2">
                <?= csrf_field() ?>
                <?= view('components/form-field', ['name' => 'first_name', 'label' => 'First name', 'value' => (string) $peopleValues['first_name'], 'maxlength' => 100, 'required' => true], ['saveData' => false]) ?>
                <?= view('components/form-field', ['name' => 'last_name', 'label' => 'Last name', 'value' => (string) $peopleValues['last_name'], 'maxlength' => 100, 'required' => true], ['saveData' => false]) ?>
                <?= view('components/form-field', ['name' => 'email', 'label' => 'Email address', 'type' => 'email', 'value' => (string) $peopleValues['email'], 'maxlength' => 254, 'required' => true, 'span' => 'md:col-span-2'], ['saveData' => false]) ?>
                <div class="md:col-span-2">
                    <button type="submit" class="inline-flex items-center justify-center pointer-coarse:min-h-11 rounded-full bg-brand px-5 py-2 text-sm font-semibold text-white transition hover:bg-brand-dark focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/50">Send invitation</button>
                </div>
            </form>
        </details>
    <?php endif; ?>
</section>

<section class="mt-6 rounded-2xl bg-white p-6 shadow-sm" data-members>
    <h2 class="text-lg font-semibold text-brand">People</h2>
    <p class="mt-1 text-sm text-[#646464]">
        <?= esc((string) $peopleMembers['total']) ?> <?= $peopleMembers['total'] === 1 ? 'person' : 'people' ?>
        <?php if ($peopleMembers['perRole'] !== []): ?>
            (<?= esc(implode(', ', array_map(static fn (string $role, int $count): string => $count . ' ' . strtolower($roleNames[$role] ?? $role), array_keys($peopleMembers['perRole']), $peopleMembers['perRole']))) ?>)
        <?php endif; ?>
    </p>

    <?php if ($peopleMembers['rows'] === []): ?>
        <p class="mt-4 text-sm text-[#646464]">Nobody belongs to this institution yet.</p>
    <?php else: ?>
        <div class="mt-4">
            <?= view('components/data-table', [
                'caption' => 'People in this institution',
                'columns' => [
                    ['key' => 'name', 'label' => 'Name'],
                    ['key' => 'email', 'label' => 'Email'],
                    ['key' => 'role', 'label' => 'Role'],
                    ['key' => 'account', 'label' => 'Account'],
                ],
                'rows'    => $memberRows,
                'id'      => 'members',
            ], ['saveData' => false]) ?>
        </div>
        <?php if ($peopleMembers['total'] > count($peopleMembers['rows'])): ?>
            <p class="mt-2 text-xs text-[#646464]">Showing the first <?= esc((string) count($peopleMembers['rows'])) ?> of <?= esc((string) $peopleMembers['total']) ?>.</p>
        <?php endif; ?>
    <?php endif; ?>
</section>

</div>

<?= $this->endSection() ?>
