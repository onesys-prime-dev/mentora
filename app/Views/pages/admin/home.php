<?php
/**
 * @var list<array{severity: string, text: string, link: string}>                              $homeAttention
 * @var array{byStatus: array<string, int>, institutions: int, users: int, signedInToday: int} $homeSnapshot
 * @var list<array<string, mixed>>                                                             $homeActivity
 */
$statusNames = ['pending' => 'Pending', 'active' => 'Active', 'read_only' => 'Read-only', 'past' => 'Past'];

$primary = 'rounded-full bg-brand px-5 py-2 text-sm font-semibold text-white transition hover:bg-brand-dark focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/50';
$second  = 'rounded-full border border-brand bg-white px-5 py-2 text-sm font-semibold text-brand transition hover:bg-brand-tint focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/50';

$surface = ['urgent' => 'border-[#C33939]/30 bg-[#FDEDEC]', 'soon' => 'border-[#946200]/30 bg-[#FFF1D6]'];
$tileLabel = 'text-xs font-semibold uppercase tracking-wide text-[#646464]';
$tileValue = 'font-display text-2xl font-bold text-brand';
?>

<?= $this->extend('layouts/admin') ?>

<?= $this->section('content') ?>

<div class="flex flex-col gap-8">

    <?= view('components/page-head', [
        'title'       => 'Home',
        'icon'        => 'apps',
        'description' => 'What needs you, and how the platform is doing.',
        'actions'     => '<span class="flex flex-wrap gap-3" data-shortcuts>'
            . '<a class="' . $primary . '" href="' . site_url('admin/institutions/new') . '">Add an institution</a>'
            . '<a class="' . $second . '" href="' . site_url('admin/institutions') . '">Record a renewal</a>'
            . '</span>',
    ], ['saveData' => false]) ?>

    <section class="flex flex-col gap-3" aria-labelledby="home-attention" data-section="attention">
        <h2 id="home-attention" class="text-lg font-bold text-brand">Needs attention</h2>

        <?php if ($homeAttention === []): ?>
            <div class="rounded-2xl bg-white p-5 shadow-sm">
                <p class="flex items-center gap-2 text-sm text-[#3D3D3D]" data-attention-empty><?= icon('check', 'h-4 w-4 text-[#2E7D4F]') ?> Nothing needs attention right now.</p>
            </div>
        <?php else: ?>
            <ul class="flex flex-col gap-3">
                <?php foreach ($homeAttention as $item): ?>
                    <?php $urgent = $item['severity'] === 'urgent'; ?>
                    <li class="flex flex-wrap items-center justify-between gap-3 rounded-2xl border px-4 py-3 text-sm <?= esc($surface[$item['severity']] ?? $surface['soon']) ?>" data-attention="<?= esc($item['severity']) ?>">
                        <span class="flex min-w-0 items-center gap-3">
                            <?= icon($urgent ? 'warning' : 'attention', 'h-4 w-4 shrink-0 text-current') ?>
                            <span class="font-semibold text-[#1A1C1E]"><span class="sr-only"><?= $urgent ? 'Urgent' : 'Soon' ?>: </span><?= esc($item['text']) ?></span>
                        </span>
                        <a class="shrink-0 rounded-full border border-brand bg-white px-4 py-1.5 text-sm font-semibold text-brand transition hover:bg-brand-tint focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/50" href="<?= site_url($item['link']) ?>">Open</a>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </section>

    <section class="flex flex-col gap-3" aria-labelledby="home-snapshot" data-section="snapshot">
        <h2 id="home-snapshot" class="text-lg font-bold text-brand">Snapshot</h2>

        <dl class="grid grid-cols-2 gap-4 sm:grid-cols-3 xl:grid-cols-6">
            <?php foreach ($statusNames as $key => $label): ?>
                <div class="rounded-2xl border border-gray-200 bg-white p-4 shadow-sm" data-status-count="<?= esc($key) ?>">
                    <dt class="<?= $tileLabel ?>"><?= esc($label) ?> institutions</dt>
                    <dd class="<?= $tileValue ?>"><?= esc((string) $homeSnapshot['byStatus'][$key]) ?></dd>
                </div>
            <?php endforeach; ?>
            <div class="rounded-2xl border border-gray-200 bg-white p-4 shadow-sm" data-total="users">
                <dt class="<?= $tileLabel ?>">Active users</dt>
                <dd class="<?= $tileValue ?>"><?= esc(number_format($homeSnapshot['users'])) ?></dd>
            </div>
            <div class="rounded-2xl border border-gray-200 bg-white p-4 shadow-sm" data-total="today">
                <dt class="<?= $tileLabel ?>">Active today</dt>
                <dd class="<?= $tileValue ?>"><?= esc(number_format($homeSnapshot['signedInToday'])) ?></dd>
            </div>
        </dl>
    </section>

    <section class="flex flex-col gap-3 rounded-2xl bg-white p-6 shadow-sm" aria-labelledby="home-activity" data-section="activity">
        <div class="flex flex-wrap items-baseline justify-between gap-2">
            <h2 id="home-activity" class="text-lg font-bold text-brand">Recent activity</h2>
            <a class="rounded text-sm font-semibold text-brand hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/50" href="<?= site_url('admin/audit-log') ?>">Audit log</a>
        </div>

        <?php if ($homeActivity === []): ?>
            <p class="text-sm text-[#646464]" data-activity-empty>Nothing has been recorded yet.</p>
        <?php else: ?>
            <ul class="flex flex-col divide-y divide-gray-100 text-sm">
                <?php foreach ($homeActivity as $row): ?>
                    <li class="flex flex-wrap items-baseline justify-between gap-2 py-2" data-activity>
                        <span class="min-w-0 text-[#1A1C1E]"><span class="font-mono text-xs"><?= esc($row['action']) ?></span> <span class="text-[#646464]">by <?= esc($row['actor_label'] ?? 'system') ?></span></span>
                        <span class="shrink-0 text-xs text-[#646464]"><?= esc(substr((string) $row['created_at'], 0, 16)) ?></span>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </section>

</div>

<?= $this->endSection() ?>
