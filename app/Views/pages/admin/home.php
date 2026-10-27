<?php
/**
 * @var list<array{severity: string, text: string, link: string}>                              $homeAttention
 * @var array{byStatus: array<string, int>, institutions: int, users: int, signedInToday: int} $homeSnapshot
 * @var list<array<string, mixed>>                                                             $homeActivity
 */
use App\Services\Institutions\InstitutionAuditLog;

$statusNames = ['pending' => 'Pending', 'read_only' => 'Read-only', 'past' => 'Past'];

$primary = 'rounded-full bg-brand px-5 py-2 text-sm font-semibold text-white transition hover:bg-brand-dark focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/50';
$second  = 'rounded-full border border-brand bg-white px-5 py-2 text-sm font-semibold text-brand transition hover:bg-brand-tint focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/50';
$ring    = 'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/50';

$tileLabel = 'text-xs font-semibold uppercase tracking-wide text-[#646464]';
$tileValue = 'font-display text-3xl font-bold';

$shortcuts = [
    ['Institutions', 'hat', 'admin/institutions'],
    ['Audit log', 'tasks', 'admin/audit-log'],
    ['Backups', 'folder', 'admin/backups'],
    ['Feature flags', 'modules', 'admin/flags'],
    ['General', 'settings', 'admin/settings/general'],
];

// The banner takes the worst item's tone; a chip is one item, linking to where it is fixed.
$urgentCount = count(array_filter($homeAttention, static fn (array $item): bool => $item['severity'] === 'urgent'));
$bannerTone  = $urgentCount > 0 ? 'border-[#C33939]/30 bg-[#FDEDEC]' : 'border-[#946200]/30 bg-[#FFF1D6]';
$chipTone    = ['urgent' => 'border-[#C33939] text-[#C33939]', 'soon' => 'border-[#946200] text-[#946200]'];
$attentionN  = count($homeAttention);

// "Today 1:28 am", "Yesterday 8:02 am", then the date.
$when = static function (string $createdAt): string {
    $time = strtotime($createdAt);

    if ($time === false) {
        return $createdAt;
    }

    $day = match (date('Y-m-d', $time)) {
        date('Y-m-d')                      => 'Today',
        date('Y-m-d', strtotime('-1 day')) => 'Yesterday',
        default                            => date('j M Y', $time),
    };

    return $day . ' ' . date('g:i a', $time);
};

// A zero is not news, so it steps back.
$value = static fn (int $n): string => $n === 0 ? 'text-[#A0A7B4]' : 'text-brand';
?>

<?= $this->extend('layouts/admin') ?>

<?= $this->section('content') ?>

<div class="flex flex-col gap-8 rounded-3xl bg-white p-5 shadow-sm sm:p-8" data-page-panel>

    <?= view('components/page-head', [
        'title'       => 'Home',
        'icon'        => 'home',
        'description' => 'What needs you, and how the platform is doing.',
        'actions'     => '<span class="flex flex-wrap gap-3" data-shortcuts>'
            . '<a class="' . $primary . '" href="' . site_url('admin/institutions/new') . '">Add an institution</a>'
            . '<a class="' . $second . '" href="' . site_url('admin/institutions') . '">Record a renewal</a>'
            . '</span>',
    ], ['saveData' => false]) ?>

    <section aria-labelledby="home-attention" data-section="attention">
        <h2 id="home-attention" class="sr-only">Needs attention</h2>

        <?php if ($homeAttention === []): ?>
            <div class="flex items-center gap-3 rounded-2xl border border-[#2E7D4F]/30 bg-[#EAF5EE] px-4 py-3 text-sm">
                <p class="flex items-center gap-2 font-semibold text-[#1F5D39]" data-attention-empty><?= icon('check', 'h-4 w-4 text-[#2E7D4F]') ?> All systems normal. Nothing needs attention right now.</p>
            </div>
        <?php else: ?>
            <div class="flex flex-wrap items-center gap-x-4 gap-y-3 rounded-2xl border px-4 py-3 text-sm <?= $bannerTone ?>">
                <p class="flex items-center gap-2 font-semibold text-[#1A1C1E]">
                    <?= icon($urgentCount > 0 ? 'warning' : 'attention', 'h-5 w-5 shrink-0 text-current') ?>
                    <?= esc((string) $attentionN) ?> <?= $attentionN === 1 ? 'thing needs' : 'things need' ?> attention
                </p>
                <ul class="flex flex-wrap gap-2 sm:ml-auto">
                    <?php foreach ($homeAttention as $item): ?>
                        <li>
                            <a class="inline-flex max-w-full items-center gap-1 rounded-full border bg-white px-3 py-1 text-xs font-semibold transition hover:bg-brand-tint <?= $ring ?> <?= esc($chipTone[$item['severity']] ?? $chipTone['soon']) ?>" href="<?= site_url($item['link']) ?>" data-attention="<?= esc($item['severity']) ?>">
                                <span class="sr-only"><?= $item['severity'] === 'urgent' ? 'Urgent' : 'Soon' ?>: </span><span class="truncate"><?= esc($item['text']) ?></span><span aria-hidden="true">›</span>
                            </a>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>
    </section>

    <section class="flex flex-col gap-3" aria-labelledby="home-snapshot" data-section="snapshot">
        <h2 id="home-snapshot" class="text-lg font-bold text-brand">Snapshot</h2>

        <dl class="grid grid-cols-1 gap-4 sm:grid-cols-3">
            <div class="rounded-2xl border border-gray-200 bg-white p-4" data-status-count="active">
                <dt class="<?= $tileLabel ?>">Active institutions</dt>
                <dd class="<?= $tileValue ?> <?= $value($homeSnapshot['byStatus']['active']) ?>"><?= esc((string) $homeSnapshot['byStatus']['active']) ?></dd>
            </div>
            <div class="rounded-2xl border border-gray-200 bg-white p-4" data-total="users">
                <dt class="<?= $tileLabel ?>">Active users</dt>
                <dd class="<?= $tileValue ?> <?= $value($homeSnapshot['users']) ?>"><?= esc(number_format($homeSnapshot['users'])) ?></dd>
            </div>
            <div class="rounded-2xl border border-gray-200 bg-white p-4" data-total="today">
                <dt class="<?= $tileLabel ?>">Active today</dt>
                <dd class="<?= $tileValue ?> <?= $value($homeSnapshot['signedInToday']) ?>"><?= esc(number_format($homeSnapshot['signedInToday'])) ?></dd>
            </div>
        </dl>

        <dl class="flex flex-wrap gap-x-5 gap-y-1 text-sm text-[#646464]">
            <?php foreach ($statusNames as $key => $label): ?>
                <div class="flex gap-1.5" data-status-count="<?= esc($key) ?>">
                    <dt><?= esc($label) ?></dt>
                    <dd class="font-semibold <?= $homeSnapshot['byStatus'][$key] === 0 ? 'text-[#A0A7B4]' : 'text-[#1A1C1E]' ?>"><?= esc((string) $homeSnapshot['byStatus'][$key]) ?></dd>
                </div>
            <?php endforeach; ?>
        </dl>
    </section>

    <section class="flex flex-col gap-3" aria-labelledby="home-shortcuts" data-section="shortcuts">
        <h2 id="home-shortcuts" class="text-lg font-bold text-brand">Shortcuts</h2>

        <ul class="flex flex-wrap gap-3">
            <?php foreach ($shortcuts as [$label, $shortcutIcon, $path]): ?>
                <li>
                    <a class="flex items-center gap-2 rounded-xl border border-gray-200 bg-white px-4 py-2.5 text-sm font-semibold text-brand transition hover:bg-brand-tint <?= $ring ?>" href="<?= site_url($path) ?>">
                        <?= icon($shortcutIcon, 'h-5 w-5 text-brand') ?><?= esc($label) ?>
                    </a>
                </li>
            <?php endforeach; ?>
        </ul>
    </section>

    <section class="flex flex-col gap-3" aria-labelledby="home-activity" data-section="activity">
        <div class="flex flex-wrap items-baseline justify-between gap-2">
            <h2 id="home-activity" class="text-lg font-bold text-brand">Recent activity</h2>
            <a class="rounded text-sm font-semibold text-brand hover:underline <?= $ring ?>" href="<?= site_url('admin/audit-log') ?>">Audit log</a>
        </div>

        <?php if ($homeActivity === []): ?>
            <p class="text-sm text-[#646464]" data-activity-empty>Nothing has been recorded yet.</p>
        <?php else: ?>
            <ul class="flex flex-col divide-y divide-gray-100 rounded-2xl border border-gray-200 text-sm">
                <?php foreach ($homeActivity as $row): ?>
                    <li class="flex flex-wrap items-baseline gap-x-4 gap-y-1 px-4 py-2.5" data-activity>
                        <span class="w-40 shrink-0 font-semibold text-[#1A1C1E]" title="<?= esc($row['action']) ?>"><?= esc(InstitutionAuditLog::label((string) $row['action'])) ?></span>
                        <span class="min-w-0 flex-1 truncate text-[#3D3D3D]"><?= esc($row['actor_label'] ?? 'System') ?></span>
                        <span class="shrink-0 text-xs text-[#646464]"><?= esc($when((string) $row['created_at'])) ?></span>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </section>

</div>

<?= $this->endSection() ?>
