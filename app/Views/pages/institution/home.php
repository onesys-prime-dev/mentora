<?php
/**
 * The institutional administrator's home.
 *
 * @var array<string, mixed> $homeInstitution
 * @var array<string, mixed> $homeSnapshot from InstitutionSnapshot::for()
 * @var array<string, mixed> $homeUsage    from PlanLimits::report()
 * @var list<array<string, mixed>> $homeAttention  from InstitutionAttention::items()
 * @var list<array<string, mixed>> $homeActivity   the latest audit entries (empty without institution.audit)
 * @var bool                 $homeCanAudit
 * @var list<array<string, mixed>> $homeShortcuts
 */
$statusTone  = ['active' => 'success', 'pending' => 'info', 'read_only' => 'warning', 'past' => 'danger'];
$statusLabel = ['active' => 'Active', 'pending' => 'Pending', 'read_only' => 'Read-only', 'past' => 'Past'];
$status      = (string) $homeInstitution['status'];

$roleLabels = ['student' => 'Students', 'instructor' => 'Instructors', 'chairperson' => 'Chairpersons', 'institutional_admin' => 'Administrators', 'parent' => 'Parents'];
$term       = $homeSnapshot['activeTerm'];
$canExport  = in_array($status, ['read_only', 'past'], true);

// The banner above already says an institution is read-only or past; do not say it twice.
$attention = array_values(array_filter($homeAttention, static fn (array $item): bool => ! ($item['key'] === 'subscription' && in_array($status, ['read_only', 'past'], true))));

$toneSurface = [
    'danger'  => 'border-[#C33939]/30 bg-[#FDEDEC]',
    'warning' => 'border-[#946200]/30 bg-[#FFF1D6]',
    'info'    => 'border-brand/20 bg-brand-tint',
];
$toneIcon = ['danger' => 'warning', 'warning' => 'attention', 'info' => 'info'];
$toneWord = ['danger' => 'Problem', 'warning' => 'Warning', 'info' => 'Note'];
?>

<?= $this->extend('layouts/app') ?>

<?= $this->section('content') ?>

<div class="flex flex-col gap-8 py-3">

    <?= view('components/page-head', [
        'title'       => (string) $homeInstitution['name'],
        'icon'        => 'hat',
        'description' => 'Your institution at a glance.',
        'actions'     => view('components/status-badge', ['label' => $statusLabel[$status] ?? ucfirst($status), 'tone' => $statusTone[$status] ?? 'neutral'], ['saveData' => false]),
    ], ['saveData' => false]) ?>

    <?php if ($status === 'read_only'): ?>
        <?= view('components/alert', ['tone' => 'warning', 'title' => 'Read-only', 'text' => 'The subscription has ended. You can still look at everything and take a full export of your data.'], ['saveData' => false]) ?>
    <?php elseif ($status === 'past'): ?>
        <?= view('components/alert', ['tone' => 'danger', 'title' => 'No longer active', 'text' => 'This institution is past. You can still take a full export of your data.'], ['saveData' => false]) ?>
    <?php endif; ?>

    <section class="flex flex-col gap-3" aria-labelledby="home-attention" data-section="attention">
        <h2 id="home-attention" class="text-lg font-bold text-brand">Needs attention</h2>

        <?php if ($attention === []): ?>
            <div class="rounded-2xl bg-white p-5 shadow-sm" data-attention-empty>
                <p class="flex items-center gap-2 text-sm text-[#3D3D3D]"><?= icon('check', 'h-4 w-4 text-[#2E7D4F]') ?> Nothing needs your attention right now.</p>
            </div>
        <?php else: ?>
            <ul class="flex flex-col gap-3">
                <?php foreach ($attention as $item): ?>
                    <li class="flex flex-wrap items-start justify-between gap-3 rounded-2xl border px-4 py-3 text-sm <?= esc($toneSurface[$item['tone']] ?? $toneSurface['info']) ?>" data-attention="<?= esc($item['key']) ?>">
                        <div class="flex min-w-0 items-start gap-3">
                            <?= icon($toneIcon[$item['tone']] ?? 'info', 'mt-0.5 h-4 w-4 shrink-0 text-current') ?>
                            <div class="min-w-0">
                                <p class="font-semibold text-[#1A1C1E]"><span class="sr-only"><?= esc($toneWord[$item['tone']] ?? 'Note') ?>: </span><?= esc($item['title']) ?></p>
                                <p class="text-[#3D3D3D]"><?= esc($item['text']) ?></p>
                            </div>
                        </div>
                        <a class="shrink-0 rounded-full border border-brand bg-white px-4 py-1.5 text-sm font-semibold text-brand transition hover:bg-brand-tint focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/50" href="<?= esc(site_url($item['url'])) ?>"><?= esc($item['action']) ?></a>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </section>

    <section class="flex flex-col gap-4" aria-labelledby="home-snapshot" data-section="snapshot">
        <h2 id="home-snapshot" class="text-lg font-bold text-brand">Snapshot</h2>

        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <?= view('components/metric-card', [
                'label'  => 'People',
                'value'  => number_format($homeSnapshot['peopleTotal']),
                'icon'   => 'user',
                'detail' => number_format($homeSnapshot['people']['student']) . ' students, ' . number_format($homeSnapshot['people']['instructor']) . ' instructors',
            ], ['saveData' => false]) ?>
            <?= view('components/metric-card', [
                'label'  => 'Courses',
                'value'  => number_format($homeSnapshot['courses']),
                'icon'   => 'hat',
                'detail' => 'Not archived',
            ], ['saveData' => false]) ?>
            <?= view('components/metric-card', [
                'label'  => 'Offerings this term',
                'value'  => number_format($homeSnapshot['offerings']),
                'icon'   => 'modules',
                'detail' => $term === null ? 'No term is active' : $term['name'] . ' ' . $term['academic_year'],
                'tone'   => $term === null ? 'warning' : 'neutral',
            ], ['saveData' => false]) ?>
            <?= view('components/metric-card', [
                'label'  => 'Enrolments this term',
                'value'  => number_format($homeSnapshot['enrolments']),
                'icon'   => 'lessons',
                'detail' => 'Students currently enrolled',
            ], ['saveData' => false]) ?>
        </div>
    </section>

    <div class="grid gap-6 lg:grid-cols-3">

        <section class="flex flex-col gap-4 rounded-2xl bg-white p-6 shadow-sm lg:col-span-2" aria-labelledby="home-plan" data-section="plan">
            <div class="flex flex-wrap items-baseline justify-between gap-2">
                <h2 id="home-plan" class="text-lg font-bold text-brand">Plan and usage</h2>
                <span class="text-sm text-[#646464]" data-plan-name><?= $homeUsage['plan'] !== null ? esc((string) $homeUsage['plan']) : 'No plan yet' ?></span>
            </div>

            <?= view('components/usage-bar', ['label' => 'Student seats', 'used' => $homeUsage['seats']['used'], 'limit' => $homeUsage['seats']['limit']], ['saveData' => false]) ?>
            <?= view('components/usage-bar', ['label' => 'Storage', 'used' => $homeUsage['storage']['used_mb'], 'limit' => $homeUsage['storage']['limit_mb'], 'unitLabel' => 'MB'], ['saveData' => false]) ?>
            <?= view('components/usage-bar', ['label' => 'AI generations this term', 'used' => $homeUsage['ai']['used'], 'limit' => $homeUsage['ai']['limit']], ['saveData' => false]) ?>
        </section>

        <section class="flex flex-col gap-3 rounded-2xl bg-white p-6 shadow-sm" aria-labelledby="home-people" data-section="people">
            <h2 id="home-people" class="text-lg font-bold text-brand">People by role</h2>
            <ul class="flex flex-col divide-y divide-gray-100 text-sm">
                <?php foreach ($roleLabels as $role => $label): ?>
                    <li class="flex items-center justify-between py-2">
                        <span class="text-[#3D3D3D]"><?= esc($label) ?></span>
                        <span class="font-semibold text-[#1A1C1E]" data-role-count="<?= esc($role) ?>"><?= esc(number_format($homeSnapshot['people'][$role])) ?></span>
                    </li>
                <?php endforeach; ?>
            </ul>
        </section>

    </div>

    <div class="grid gap-6 lg:grid-cols-3">

        <section class="flex flex-col gap-3 rounded-2xl bg-white p-6 shadow-sm lg:col-span-2" aria-labelledby="home-activity" data-section="activity">
            <div class="flex flex-wrap items-baseline justify-between gap-2">
                <h2 id="home-activity" class="text-lg font-bold text-brand">Recent activity</h2>
                <?php if ($homeCanAudit): ?>
                    <a class="rounded text-sm font-semibold text-brand hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/50" href="<?= esc(site_url('institution/audit')) ?>">Open the audit log</a>
                <?php endif; ?>
            </div>

            <?php if (! $homeCanAudit): ?>
                <p class="text-sm text-[#646464]">You do not have access to the audit log.</p>
            <?php elseif ($homeActivity === []): ?>
                <p class="text-sm text-[#646464]" data-activity-empty>Nothing has been recorded yet. What people do in your institution appears here.</p>
            <?php else: ?>
                <ul class="flex flex-col divide-y divide-gray-100 text-sm">
                    <?php foreach ($homeActivity as $entry): ?>
                        <li class="flex flex-wrap items-baseline justify-between gap-2 py-2" data-activity>
                            <span class="min-w-0 text-[#1A1C1E]">
                                <span class="font-semibold"><?= esc((string) $entry['action_label']) ?></span>
                                <span class="text-[#646464]">by <?= esc((string) $entry['actor']) ?></span>
                            </span>
                            <time class="shrink-0 text-xs text-[#646464]" datetime="<?= esc((string) $entry['created_at']) ?>"><?= esc(date('j M Y H:i', strtotime((string) $entry['created_at']))) ?></time>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </section>

        <section class="flex flex-col gap-3 rounded-2xl bg-white p-6 shadow-sm" aria-labelledby="home-shortcuts" data-section="shortcuts">
            <h2 id="home-shortcuts" class="text-lg font-bold text-brand">Quick access</h2>

            <ul class="flex flex-col gap-2">
                <?php foreach ($homeShortcuts as $shortcut): ?>
                    <li>
                        <a class="flex min-h-10 items-center gap-3 rounded-xl px-3 py-2 text-sm font-semibold text-brand transition hover:bg-brand-tint focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/50" href="<?= esc($shortcut['url']) ?>">
                            <?= icon($shortcut['icon'], 'h-4 w-4') ?><?= esc($shortcut['label']) ?>
                        </a>
                    </li>
                <?php endforeach; ?>
                <li>
                    <a class="flex min-h-10 items-center gap-3 rounded-xl px-3 py-2 text-sm font-semibold text-brand transition hover:bg-brand-tint focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/50" href="<?= esc(site_url('institution/export')) ?>">
                        <?= icon('folder', 'h-4 w-4') ?>Data export
                    </a>
                    <?php if (! $canExport): ?>
                        <p class="px-3 text-xs text-[#646464]">A full export of your data opens when the subscription ends.</p>
                    <?php endif; ?>
                </li>
            </ul>
        </section>

    </div>

</div>

<?= $this->endSection() ?>
