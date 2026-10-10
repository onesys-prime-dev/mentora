<?php
/**
 * @var list<array{severity: string, text: string, link: string}>                              $homeAttention
 * @var array{byStatus: array<string, int>, institutions: int, users: int, signedInToday: int} $homeSnapshot
 * @var list<array<string, mixed>>                                                             $homeActivity
 */
$statusNames = ['pending' => 'Pending', 'active' => 'Active', 'read_only' => 'Read-only', 'past' => 'Past'];
?>

<?= $this->extend('layouts/admin') ?>

<?= $this->section('content') ?>

<div class="flex flex-wrap items-center justify-between gap-3">
    <div>
        <h1 class="text-2xl font-bold text-[#0040A6]">Home</h1>
        <p class="mt-1 text-sm text-gray-600">What needs you, and how the platform is doing.</p>
    </div>
    <div class="flex flex-wrap gap-2" data-shortcuts>
        <a class="rounded bg-[#0040A6] px-4 py-2 text-sm font-semibold text-white hover:bg-[#002068]" href="<?= site_url('admin/institutions/new') ?>">Add an institution</a>
        <a class="rounded border border-[#0040A6] px-4 py-2 text-sm font-semibold text-[#0040A6] hover:bg-[#0040A6]/10" href="<?= site_url('admin/institutions') ?>">Record a renewal</a>
    </div>
</div>

<section class="mt-6 rounded-lg bg-white p-6 shadow" data-section="attention">
    <h2 class="text-lg font-semibold text-[#0040A6]">Needs attention</h2>
    <?php if ($homeAttention === []): ?>
        <p class="mt-3 text-sm text-green-700" data-attention-empty>Nothing needs attention right now.</p>
    <?php else: ?>
        <ul class="mt-3 divide-y divide-gray-100 text-sm">
            <?php foreach ($homeAttention as $item): ?>
                <li class="flex flex-wrap items-center justify-between gap-2 py-2" data-attention="<?= esc($item['severity']) ?>">
                    <span>
                        <span class="mr-2 rounded-full px-2 py-0.5 text-xs font-semibold <?= $item['severity'] === 'urgent' ? 'bg-red-100 text-red-800' : 'bg-amber-100 text-amber-800' ?>"><?= $item['severity'] === 'urgent' ? 'Urgent' : 'Soon' ?></span>
                        <?= esc($item['text']) ?>
                    </span>
                    <a class="font-semibold text-[#0040A6] hover:underline" href="<?= site_url($item['link']) ?>">Open</a>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</section>

<section class="mt-6 rounded-lg bg-white p-6 shadow" data-section="snapshot">
    <h2 class="text-lg font-semibold text-[#0040A6]">Snapshot</h2>
    <dl class="mt-3 grid grid-cols-2 gap-4 text-sm sm:grid-cols-4 lg:grid-cols-6">
        <?php foreach ($statusNames as $key => $label): ?>
            <div data-status-count="<?= esc($key) ?>"><dt class="text-gray-500"><?= esc($label) ?> institutions</dt><dd class="text-xl font-semibold text-gray-900"><?= esc((string) $homeSnapshot['byStatus'][$key]) ?></dd></div>
        <?php endforeach; ?>
        <div data-total="users"><dt class="text-gray-500">Active users</dt><dd class="text-xl font-semibold text-gray-900"><?= esc(number_format($homeSnapshot['users'])) ?></dd></div>
        <div data-total="today"><dt class="text-gray-500">Active today</dt><dd class="text-xl font-semibold text-gray-900"><?= esc(number_format($homeSnapshot['signedInToday'])) ?></dd></div>
    </dl>
</section>

<section class="mt-6 rounded-lg bg-white p-6 shadow" data-section="activity">
    <div class="flex items-center justify-between">
        <h2 class="text-lg font-semibold text-[#0040A6]">Recent activity</h2>
        <a class="text-sm font-semibold text-[#0040A6] hover:underline" href="<?= site_url('admin/audit-log') ?>">Audit log</a>
    </div>
    <?php if ($homeActivity === []): ?>
        <p class="mt-3 text-sm text-gray-600" data-activity-empty>Nothing has been recorded yet.</p>
    <?php else: ?>
        <ul class="mt-3 divide-y divide-gray-100 text-sm">
            <?php foreach ($homeActivity as $row): ?>
                <li class="flex flex-wrap justify-between gap-2 py-2" data-activity>
                    <span><span class="font-mono text-xs"><?= esc($row['action']) ?></span> by <?= esc($row['actor_label'] ?? 'system') ?></span>
                    <span class="text-xs text-gray-500"><?= esc(substr((string) $row['created_at'], 0, 16)) ?></span>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</section>

<?= $this->endSection() ?>
