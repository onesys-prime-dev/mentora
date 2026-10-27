<?php
/**
 * The administrator's Home, laid out as a console: ruled and dense, with the same rounded corners as the rest of the admin area. A
 * strip of platform checks, what needs fixing, the key numbers, shortcuts and a
 * table of recent activity.
 *
 * @var list<array{severity: string, text: string, link: string}>                              $homeAttention
 * @var list<array{key: string, label: string, status: string, detail: string}>                $homeHealth
 * @var array{byStatus: array<string, int>, institutions: int, users: int, signedInToday: int} $homeSnapshot
 * @var list<array<string, mixed>>                                                             $homeActivity
 */
use App\Services\Institutions\InstitutionAuditLog;
use App\Services\Monitoring\SystemHealth;

$statusNames = ['pending' => 'Pending', 'read_only' => 'Read-only', 'past' => 'Past'];

$ring    = 'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/50';
$primary = 'rounded-full border border-brand bg-brand px-5 py-2 text-sm font-semibold text-white transition hover:bg-brand-dark ' . $ring;
$second  = 'rounded-full border border-brand bg-white px-5 py-2 text-sm font-semibold text-brand transition hover:bg-brand-tint ' . $ring;

$cellLabel = 'text-[11px] font-semibold uppercase tracking-wide text-[#646464]';
$rule      = 'border-gray-300';

// A status is a dot, a word and (for a screen reader) the check's own detail, never colour alone.
$healthTone = [
    SystemHealth::UP       => ['bg-[#2E7D4F]', 'Normal'],
    SystemHealth::DEGRADED => ['bg-[#D99100]', 'Needs a look'],
    SystemHealth::DOWN     => ['bg-[#C33939]', 'Down'],
];

$shortcuts = [
    ['Institutions', 'hat', 'admin/institutions'],
    ['Audit log', 'tasks', 'admin/audit-log'],
    ['Backups', 'folder', 'admin/backups'],
    ['Feature flags', 'modules', 'admin/flags'],
    ['General', 'settings', 'admin/settings/general'],
];

$urgentCount = count(array_filter($homeAttention, static fn (array $item): bool => $item['severity'] === 'urgent'));
$attentionN  = count($homeAttention);
$chipTone    = ['urgent' => 'border-[#C33939] text-[#C33939]', 'soon' => 'border-[#946200] text-[#946200]'];

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

<div class="flex flex-col gap-6 rounded-3xl bg-white p-5 shadow-sm sm:p-8" data-page-panel>

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

        <div class="overflow-hidden rounded-2xl border <?= $rule ?>">

        <?php if ($homeHealth !== []): ?>
            <ul class="-mr-px grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-5" data-health>
                <?php foreach ($homeHealth as $cell): ?>
                    <?php [$dot, $word] = $healthTone[$cell['status']] ?? $healthTone[SystemHealth::DEGRADED]; ?>
                    <li class="border-b border-r <?= $rule ?> px-3 py-2.5" data-health-cell="<?= esc($cell['key']) ?>" title="<?= esc($cell['detail']) ?>">
                        <p class="<?= $cellLabel ?>"><?= esc($cell['label']) ?></p>
                        <p class="mt-1 flex items-center gap-2 text-sm font-semibold text-[#1A1C1E]">
                            <span class="h-2 w-2 shrink-0 rounded-full <?= $dot ?>" aria-hidden="true"></span><?= esc($word) ?>
                            <span class="sr-only">: <?= esc($cell['detail']) ?></span>
                        </p>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>

        <?php if ($homeAttention === []): ?>
            <div class="flex items-center gap-3 bg-gray-50 px-3 py-2.5 text-sm">
                <p class="flex items-center gap-2 font-semibold text-[#1F5D39]" data-attention-empty><?= icon('check', 'h-4 w-4 text-[#2E7D4F]') ?> All systems normal. Nothing needs attention right now.</p>
            </div>
        <?php else: ?>
            <div class="flex flex-wrap items-center gap-x-4 gap-y-2 bg-gray-50 px-3 py-2.5 text-sm">
                <p class="flex items-center gap-2 font-semibold <?= $urgentCount > 0 ? 'text-[#C33939]' : 'text-[#946200]' ?>">
                    <?= icon($urgentCount > 0 ? 'warning' : 'attention', 'h-4 w-4 shrink-0 text-current') ?>
                    <?= esc((string) $attentionN) ?> to fix
                </p>
                <ul class="flex flex-wrap gap-2">
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

        </div>
    </section>

    <section class="flex flex-col gap-2" aria-labelledby="home-snapshot" data-section="snapshot">
        <h2 id="home-snapshot" class="text-base font-bold text-brand">Snapshot</h2>

        <div class="overflow-hidden rounded-2xl border <?= $rule ?>">
        <dl class="-mb-px -mr-px grid grid-cols-1 sm:grid-cols-3">
            <div class="border-b border-r <?= $rule ?> px-3 py-3" data-status-count="active">
                <dt class="<?= $cellLabel ?>">Active institutions</dt>
                <dd class="font-display text-3xl font-bold <?= $value($homeSnapshot['byStatus']['active']) ?>"><?= esc((string) $homeSnapshot['byStatus']['active']) ?></dd>
            </div>
            <div class="border-b border-r <?= $rule ?> px-3 py-3" data-total="users">
                <dt class="<?= $cellLabel ?>">Active users</dt>
                <dd class="font-display text-3xl font-bold <?= $value($homeSnapshot['users']) ?>"><?= esc(number_format($homeSnapshot['users'])) ?></dd>
            </div>
            <div class="border-b border-r <?= $rule ?> px-3 py-3" data-total="today">
                <dt class="<?= $cellLabel ?>">Active today</dt>
                <dd class="font-display text-3xl font-bold <?= $value($homeSnapshot['signedInToday']) ?>"><?= esc(number_format($homeSnapshot['signedInToday'])) ?></dd>
            </div>
        </dl>
        </div>

        <dl class="flex flex-wrap gap-x-5 gap-y-1 text-sm text-[#646464]">
            <?php foreach ($statusNames as $key => $label): ?>
                <div class="flex gap-1.5" data-status-count="<?= esc($key) ?>">
                    <dt><?= esc($label) ?></dt>
                    <dd class="font-semibold <?= $homeSnapshot['byStatus'][$key] === 0 ? 'text-[#A0A7B4]' : 'text-[#1A1C1E]' ?>"><?= esc((string) $homeSnapshot['byStatus'][$key]) ?></dd>
                </div>
            <?php endforeach; ?>
        </dl>
    </section>

    <section class="flex flex-col gap-2" aria-labelledby="home-shortcuts" data-section="shortcuts">
        <h2 id="home-shortcuts" class="text-base font-bold text-brand">Shortcuts</h2>

        <ul class="flex flex-wrap gap-2">
            <?php foreach ($shortcuts as [$label, $shortcutIcon, $path]): ?>
                <li>
                    <a class="flex items-center gap-2 rounded-xl border <?= $rule ?> bg-white px-3 py-2 text-sm font-semibold text-brand transition hover:bg-brand-tint <?= $ring ?>" href="<?= site_url($path) ?>">
                        <?= icon($shortcutIcon, 'h-4 w-4 text-brand') ?><?= esc($label) ?>
                    </a>
                </li>
            <?php endforeach; ?>
        </ul>
    </section>

    <section class="flex flex-col gap-2" aria-labelledby="home-activity" data-section="activity">
        <div class="flex flex-wrap items-baseline justify-between gap-2">
            <h2 id="home-activity" class="text-base font-bold text-brand">Recent activity</h2>
            <a class="text-sm font-semibold text-brand hover:underline <?= $ring ?>" href="<?= site_url('admin/audit-log') ?>">Audit log</a>
        </div>

        <?php if ($homeActivity === []): ?>
            <p class="text-sm text-[#646464]" data-activity-empty>Nothing has been recorded yet.</p>
        <?php else: ?>
            <div class="overflow-hidden rounded-2xl border <?= $rule ?>">
            <table class="w-full text-sm">
                <caption class="sr-only">Recent activity</caption>
                <thead class="max-sm:sr-only border-b <?= $rule ?> bg-gray-50">
                    <tr>
                        <th class="px-3 py-2 text-left <?= $cellLabel ?>" scope="col">Action</th>
                        <th class="px-3 py-2 text-left <?= $cellLabel ?>" scope="col">Person</th>
                        <th class="px-3 py-2 text-right <?= $cellLabel ?>" scope="col">When</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200">
                    <?php foreach ($homeActivity as $row): ?>
                        <tr data-activity class="max-sm:block max-sm:px-3 max-sm:py-2">
                            <td class="px-3 py-2 font-semibold text-[#1A1C1E] max-sm:block max-sm:px-0 max-sm:py-0" title="<?= esc($row['action']) ?>"><?= esc(InstitutionAuditLog::label((string) $row['action'])) ?></td>
                            <td class="px-3 py-2 text-[#3D3D3D] max-sm:block max-sm:px-0 max-sm:py-0"><?= esc($row['actor_label'] ?? 'System') ?></td>
                            <td class="px-3 py-2 text-right text-xs text-[#646464] max-sm:block max-sm:px-0 max-sm:py-0 max-sm:text-left"><?= esc($when((string) $row['created_at'])) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            </div>
        <?php endif; ?>
    </section>

</div>

<?= $this->endSection() ?>
