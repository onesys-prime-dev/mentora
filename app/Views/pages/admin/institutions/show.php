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
    'active'    => ['Active', 'bg-green-100 text-green-800'],
    'read_only' => ['Read-only', 'bg-amber-100 text-amber-800'],
    'past'      => ['Past', 'bg-red-100 text-red-800'],
];
[$statusLabel, $statusClass] = $statusLabels[$profInstitution['status']] ?? [$profInstitution['status'], 'bg-gray-100 text-gray-700'];

$invitationBadge = [
    'pending'  => ['Waiting for them', 'bg-amber-100 text-amber-800'],
    'accepted' => ['Accepted', 'bg-green-100 text-green-800'],
    'revoked'  => ['Replaced', 'bg-gray-100 text-gray-700'],
    'expired'  => ['Expired', 'bg-red-100 text-red-800'],
];

$isDeleted = $profInstitution['deleted_at'] !== null;
$latest    = $profInvitations[0] ?? null;
$btn       = 'rounded border px-2.5 py-1 text-xs font-semibold';
?>

<?= $this->extend('layouts/admin') ?>

<?= $this->section('content') ?>

<a class="text-sm font-semibold text-[#0040A6] hover:underline" href="<?= site_url('admin/institutions') ?>">&larr; Institutions</a>

<div class="mt-2 flex flex-wrap items-center gap-3">
    <h1 class="text-2xl font-bold text-[#0040A6]"><?= esc($profInstitution['name']) ?></h1>
    <span class="rounded-full px-2 py-0.5 text-xs font-semibold <?= $statusClass ?>" data-institution-status="<?= esc($profInstitution['status']) ?>"><?= esc($statusLabel) ?></span>
    <?php if ($isDeleted): ?><span class="rounded-full bg-gray-800 px-2 py-0.5 text-xs font-semibold text-white">Deleted</span><?php endif; ?>
</div>
<p class="mt-1 text-sm text-gray-600"><span class="font-mono text-xs"><?= esc($profInstitution['code']) ?></span></p>

<?= view('pages/admin/partials/institution-tabs', ['tabInstitution' => $profInstitution, 'tabActive' => 'profile']) ?>

<?php if (session()->has('message')): ?>
    <p class="mt-4 rounded bg-green-50 px-3 py-2 text-sm text-green-700" role="status"><?= esc((string) session('message')) ?></p>
<?php endif; ?>
<?php if (session()->has('error')): ?>
    <p class="mt-4 rounded bg-red-50 px-3 py-2 text-sm text-red-700" role="alert"><?= esc((string) session('error')) ?></p>
<?php endif; ?>

<?php if ($profInstitution['status'] === 'pending' && ! $isDeleted): ?>
    <div class="mt-6 rounded-lg border border-amber-300 bg-amber-50 p-5" data-onboarding-pending>
        <h2 class="text-lg font-semibold text-amber-900">Onboarding is not finished</h2>
        <p class="mt-1 text-sm text-amber-900">This institution has no subscription and no administrator yet, so nothing in it can be used. Choose its plan and invite its first administrator to finish.</p>
        <a class="mt-3 inline-block rounded bg-[#0040A6] px-4 py-2 text-sm font-semibold text-white hover:bg-[#002068]" href="<?= site_url('admin/institutions/' . $profInstitution['id'] . '/onboarding') ?>">Continue onboarding</a>
    </div>
<?php endif; ?>

<div class="mt-6 grid gap-6 lg:grid-cols-2">
    <section class="rounded-lg bg-white p-6 shadow" data-profile-details>
        <h2 class="text-lg font-semibold text-[#0040A6]">Details</h2>
        <dl class="mt-3 grid grid-cols-[auto_1fr] gap-x-4 gap-y-2 text-sm">
            <dt class="text-gray-500">Name</dt><dd><?= esc($profInstitution['name']) ?></dd>
            <dt class="text-gray-500">Code</dt><dd class="font-mono text-xs"><?= esc($profInstitution['code']) ?></dd>
            <dt class="text-gray-500">Status</dt><dd><?= esc($statusLabel) ?></dd>
            <dt class="text-gray-500">Added</dt><dd><?= esc(substr((string) $profInstitution['created_at'], 0, 10)) ?></dd>
            <dt class="text-gray-500">People</dt><dd><?= esc((string) $profMembers['total']) ?></dd>
        </dl>
        <?php if (! $isDeleted): ?>
            <a class="<?= $btn ?> mt-4 inline-block border-gray-300 text-gray-700 hover:bg-gray-50" href="<?= site_url('admin/institutions/' . $profInstitution['id'] . '/edit') ?>">Edit</a>
        <?php endif; ?>
    </section>

    <section class="rounded-lg bg-white p-6 shadow" data-profile-subscription>
        <h2 class="text-lg font-semibold text-[#0040A6]">Subscription</h2>
        <?php if ($profSubscription === null): ?>
            <p class="mt-3 text-sm text-gray-600">None yet.</p>
        <?php else: ?>
            <p class="mt-3 text-sm text-gray-700"><strong><?= esc($profSubscription['plan_name']) ?></strong>, from <?= esc($profSubscription['start_date']) ?> to <?= esc($profSubscription['end_date']) ?>.</p>
            <?php if ($profStatus !== null): ?><p class="mt-1 text-sm text-gray-600">Timeline: <?= esc(str_replace('_', '-', $profStatus->state)) ?>.</p><?php endif; ?>
            <a class="<?= $btn ?> mt-4 inline-block border-gray-300 text-gray-700 hover:bg-gray-50" href="<?= site_url('admin/institutions/' . $profInstitution['id'] . '/subscription') ?>">Subscription and renewals</a>
        <?php endif; ?>
    </section>

    <section class="rounded-lg bg-white p-6 shadow lg:col-span-2" data-profile-administrator>
        <h2 class="text-lg font-semibold text-[#0040A6]">Administrator</h2>
        <?php if ($latest === null): ?>
            <p class="mt-3 text-sm text-gray-600">No administrator has been invited yet.</p>
        <?php else: ?>
            <?php [$badgeText, $badgeClass] = $invitationBadge[$latest['status']] ?? [$latest['status'], 'bg-gray-100 text-gray-700']; ?>
            <p class="mt-3 text-sm text-gray-700">
                <strong><?= esc($latest['first_name'] . ' ' . $latest['last_name']) ?></strong> (<?= esc($latest['email']) ?>)
                <span class="ml-2 rounded-full px-2 py-0.5 text-xs font-semibold <?= $badgeClass ?>" data-invitation-status="<?= esc($latest['status']) ?>"><?= esc($badgeText) ?></span>
            </p>
        <?php endif; ?>
        <a class="<?= $btn ?> mt-4 inline-block border-gray-300 text-gray-700 hover:bg-gray-50" href="<?= site_url('admin/institutions/' . $profInstitution['id'] . '/users') ?>">People and invitations</a>
    </section>
</div>

<?= $this->endSection() ?>
