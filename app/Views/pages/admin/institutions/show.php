<?php
/**
 * An institution's profile: who it is, where it stands, and what is left to do.
 *
 * @var array<string, mixed>       $profInstitution
 * @var array<string, mixed>|null  $profSubscription  null when it has none
 * @var object|null                $profStatus        SubscriptionStatus, null when there is no subscription
 * @var list<array<string, mixed>> $profInvitations   newest first, each with `status`
 * @var array{total: int, perRole: array<string, int>, rows: list<array<string, mixed>>} $profMembers
 */
$statusLabels = [
    'pending'   => ['Pending', 'bg-gray-100 text-gray-700'],
    'active'    => ['Active', 'bg-[#E8F5EC] text-[#2B7549]'],
    'read_only' => ['Read-only', 'bg-[#FFF1D6] text-[#946200]'],
    'past'      => ['Past', 'bg-[#FDEDEC] text-[#C33939]'],
];
[$statusLabel, $statusClass] = $statusLabels[$profInstitution['status']] ?? [$profInstitution['status'], 'bg-gray-100 text-gray-700'];

$invitationBadge = [
    'pending'  => ['Waiting for them', 'bg-[#FFF1D6] text-[#946200]'],
    'accepted' => ['Accepted', 'bg-[#E8F5EC] text-[#2B7549]'],
    'revoked'  => ['Replaced', 'bg-gray-100 text-gray-700'],
    'expired'  => ['Expired', 'bg-[#FDEDEC] text-[#C33939]'],
];

$isDeleted = $profInstitution['deleted_at'] !== null;
$latest    = $profInvitations[0] ?? null;
$btn       = 'rounded-full border px-3 py-1 text-xs font-semibold transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/50';
?>

<?= $this->extend('layouts/admin') ?>

<?= $this->section('content') ?>

<?= view('components/page-head', ['title' => $profInstitution['name'], 'icon' => 'hat', 'backUrl' => site_url('admin/institutions'), 'backLabel' => 'Institutions', 'actions' => '<span class="rounded-full px-2 py-0.5 text-xs font-semibold ' . $statusClass . '" data-institution-status="' . esc($profInstitution['status']) . '">' . esc($statusLabel) . '</span>' . ($isDeleted ? '<span class="rounded-full bg-gray-800 px-2 py-0.5 text-xs font-semibold text-white">Deleted</span>' : '')], ['saveData' => false]) ?>
<p class="mt-1 text-sm text-[#646464]"><span class="font-mono text-xs"><?= esc($profInstitution['code']) ?></span></p>

<?= view('pages/admin/partials/institution-tabs', ['tabInstitution' => $profInstitution, 'tabActive' => 'profile']) ?>

<?php if (session()->has('message')): ?>
    <div class="mt-4"><?= view('components/alert', ['tone' => 'success', 'text' => (string) session('message')], ['saveData' => false]) ?></div>
<?php endif; ?>
<?php if (session()->has('error')): ?>
    <div class="mt-4"><?= view('components/alert', ['tone' => 'danger', 'text' => (string) session('error')], ['saveData' => false]) ?></div>
<?php endif; ?>

<?php if ($profInstitution['status'] === 'pending' && ! $isDeleted): ?>
    <div class="mt-6 rounded-2xl border border-[#946200]/30 bg-[#FFF1D6] p-5" data-onboarding-pending>
        <h2 class="text-lg font-semibold text-amber-900">Onboarding is not finished</h2>
        <p class="mt-1 text-sm text-amber-900">This institution has no subscription and no administrator yet, so nothing in it can be used. Choose its plan and invite its first administrator to finish.</p>
        <a class="mt-3 inline-block rounded-full bg-brand px-5 py-2 text-sm font-semibold text-white transition hover:bg-brand-dark focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/50" href="<?= site_url('admin/institutions/' . $profInstitution['id'] . '/onboarding') ?>">Continue onboarding</a>
    </div>
<?php endif; ?>

<div class="mt-6 grid gap-6 lg:grid-cols-2">
    <section class="rounded-2xl bg-white p-6 shadow-sm" data-profile-details>
        <h2 class="text-lg font-semibold text-brand">Details</h2>
        <dl class="mt-3 grid grid-cols-[auto_1fr] gap-x-4 gap-y-2 text-sm">
            <dt class="text-[#646464]">Name</dt><dd><?= esc($profInstitution['name']) ?></dd>
            <dt class="text-[#646464]">Code</dt><dd class="font-mono text-xs"><?= esc($profInstitution['code']) ?></dd>
            <dt class="text-[#646464]">Status</dt><dd><?= esc($statusLabel) ?></dd>
            <dt class="text-[#646464]">Added</dt><dd><?= esc(substr((string) $profInstitution['created_at'], 0, 10)) ?></dd>
            <dt class="text-[#646464]">People</dt><dd><?= esc((string) $profMembers['total']) ?></dd>
        </dl>
        <?php if (! $isDeleted): ?>
            <a class="<?= $btn ?> mt-4 inline-block border-gray-300 bg-white text-[#3D3D3D] hover:bg-gray-50" href="<?= site_url('admin/institutions/' . $profInstitution['id'] . '/edit') ?>">Edit</a>
        <?php endif; ?>
    </section>

    <section class="rounded-2xl bg-white p-6 shadow-sm" data-profile-subscription>
        <h2 class="text-lg font-semibold text-brand">Subscription</h2>
        <?php if ($profSubscription === null): ?>
            <p class="mt-3 text-sm text-[#646464]">None yet.</p>
        <?php else: ?>
            <p class="mt-3 text-sm text-gray-700"><strong><?= esc($profSubscription['plan_name']) ?></strong>, from <?= esc($profSubscription['start_date']) ?> to <?= esc($profSubscription['end_date']) ?>.</p>
            <?php if ($profStatus !== null): ?><p class="mt-1 text-sm text-[#646464]">Timeline: <?= esc(str_replace('_', '-', $profStatus->state)) ?>.</p><?php endif; ?>
            <a class="<?= $btn ?> mt-4 inline-block border-gray-300 bg-white text-[#3D3D3D] hover:bg-gray-50" href="<?= site_url('admin/institutions/' . $profInstitution['id'] . '/subscription') ?>">Subscription and renewals</a>
        <?php endif; ?>
    </section>

    <section class="rounded-2xl bg-white p-6 shadow-sm lg:col-span-2" data-profile-administrator>
        <h2 class="text-lg font-semibold text-brand">Administrator</h2>
        <?php if ($latest === null): ?>
            <p class="mt-3 text-sm text-[#646464]">No administrator has been invited yet.</p>
        <?php else: ?>
            <?php [$badgeText, $badgeClass] = $invitationBadge[$latest['status']] ?? [$latest['status'], 'bg-gray-100 text-gray-700']; ?>
            <p class="mt-3 text-sm text-gray-700">
                <strong><?= esc($latest['first_name'] . ' ' . $latest['last_name']) ?></strong> (<?= esc($latest['email']) ?>)
                <span class="ml-2 rounded-full px-2 py-0.5 text-xs font-semibold <?= $badgeClass ?>" data-invitation-status="<?= esc($latest['status']) ?>"><?= esc($badgeText) ?></span>
            </p>
        <?php endif; ?>
        <a class="<?= $btn ?> mt-4 inline-block border-gray-300 bg-white text-[#3D3D3D] hover:bg-gray-50" href="<?= site_url('admin/institutions/' . $profInstitution['id'] . '/users') ?>">People and invitations</a>
    </section>
</div>

<?= $this->endSection() ?>
