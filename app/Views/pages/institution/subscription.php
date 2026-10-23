<?php
/**
 * The institution's subscription, read only.
 *
 * @var array<string, mixed>|null                          $planInfo
 * @var \App\Services\Subscriptions\SubscriptionStatus|null $planStatus
 * @var array<string, mixed>                               $planUsage
 * @var list<array<string, mixed>>                         $planHistory
 */
$tones = ['active' => 'success', 'expiring' => 'warning', 'grace' => 'warning', 'read_only' => 'warning', 'expired' => 'danger'];
$names = ['active' => 'Running', 'expiring' => 'Expiring soon', 'grace' => 'Grace period', 'read_only' => 'Read-only', 'expired' => 'Expired'];
$kinds = ['start' => 'Started', 'renewal' => 'Renewal', 'correction' => 'Correction'];
$date  = static fn (string $d): string => date('j M Y', strtotime($d));

$say = static function ($s) use ($date): string {
    return match ($s->state) {
        'active'    => $s->daysToEnd . ' days until the end date.',
        'expiring'  => $s->daysToEnd === 0 ? 'Ends today.' : 'Ends in ' . $s->daysToEnd . ' day' . ($s->daysToEnd === 1 ? '' : 's') . '. Ask your Mentora contact to renew it.',
        'grace'     => 'The end date has passed. Everything still works until ' . $date($s->graceEnds) . '; after that it becomes read-only.',
        'read_only' => 'Your institution is read-only: people can sign in, look and export, but not change anything. It stays so until ' . $date($s->readOnlyEnds) . '.',
        default     => 'The subscription has ended. Your data is kept, and you can take a full export.',
    };
};

$rows = [];

foreach ($planHistory as $i => $h) {
    $rows[] = [
        'id'    => $i,
        'label' => $kinds[$h['kind']] ?? $h['kind'],
        'cells' => [
            'recorded'  => $date($h['recorded_at']),
            'kind'      => ($kinds[$h['kind']] ?? $h['kind']) . ($h['corrects'] ? ' (puts an earlier record right)' : ''),
            'plan'      => $h['plan_name'],
            'period'    => $date($h['period_start']) . ' to ' . $date($h['period_end']),
            'amount'    => $h['amount'] === null ? '—' : $h['currency'] . ' ' . number_format((float) $h['amount'], 2),
            'reference' => (string) ($h['reference'] ?? '—'),
        ],
    ];
}
?>

<?= $this->extend('layouts/app') ?>

<?= $this->section('content') ?>

<div class="py-3">

<?php // The way back stays on the background, above the panel, not in the box. ?>
<?= view('components/back-link', ['backUrl' => site_url('institution/dashboard'), 'backLabel' => 'Back to home'], ['saveData' => false]) ?>

<div class="flex flex-col gap-6 rounded-3xl bg-white p-5 shadow-sm sm:p-8" data-page-panel>

    <?= view('components/page-head', [
        'title'       => 'Subscription',
        'icon'        => 'info',
        'description' => 'Your plan, how long it runs, and how much of it you are using.',
    ], ['saveData' => false]) ?>

    <?php if ($planInfo === null): ?>

        <div class="rounded-2xl border border-[#E3E6EE] bg-[#F6F7FA]">
            <?= view('components/empty-state', ['icon' => 'info', 'title' => 'No subscription yet', 'text' => 'Your Mentora contact sets up your plan. It will appear here.'], ['saveData' => false]) ?>
        </div>

    <?php else: ?>

        <section class="rounded-2xl border border-[#E3E6EE] bg-[#F6F7FA] p-6 shadow-md" aria-labelledby="plan-heading" data-subscription>
            <div class="flex flex-wrap items-center gap-3">
                <h2 id="plan-heading" class="text-lg font-bold text-brand"><?= esc($planInfo['name']) ?></h2>
                <?= view('components/status-badge', ['label' => $names[$planStatus->state] ?? $planStatus->state, 'tone' => $tones[$planStatus->state] ?? 'neutral'], ['saveData' => false]) ?>
                <?php if ($planInfo['retired']): ?>
                    <span class="rounded-full bg-gray-100 px-2 py-0.5 text-xs font-semibold text-[#646464]">This plan is no longer sold; yours continues</span>
                <?php endif; ?>
            </div>

            <p class="mt-3 text-sm text-[#1A1C1E]">From <strong><?= esc($date($planInfo['start_date'])) ?></strong> to <strong><?= esc($date($planInfo['end_date'])) ?></strong>.</p>
            <p class="mt-1 text-sm text-[#3D3D3D]" data-subscription-state><?= esc($say($planStatus)) ?></p>

            <?php if (in_array($planStatus->state, ['read_only', 'expired'], true)): ?>
                <p class="mt-3"><a class="rounded-full border border-brand bg-white px-5 py-2 text-sm font-semibold text-brand transition hover:bg-brand-tint focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/50" href="<?= esc(site_url('institution/export')) ?>">Take a full export of your data</a></p>
            <?php endif; ?>
        </section>

        <section class="flex flex-col gap-4 rounded-2xl border border-[#E3E6EE] bg-[#F6F7FA] p-6 shadow-md" aria-labelledby="usage-heading" data-usage>
            <h2 id="usage-heading" class="text-sm font-bold text-brand">Use against your plan</h2>

            <?= view('components/usage-bar', ['label' => 'Student seats', 'used' => $planUsage['seats']['used'], 'limit' => $planUsage['seats']['limit']], ['saveData' => false]) ?>
            <?= view('components/usage-bar', ['label' => 'Storage', 'used' => $planUsage['storage']['used_mb'], 'limit' => $planUsage['storage']['limit_mb'], 'unitLabel' => 'MB'], ['saveData' => false]) ?>
            <?= view('components/usage-bar', ['label' => 'AI question generations this term', 'used' => $planUsage['ai']['used'], 'limit' => $planUsage['ai']['limit']], ['saveData' => false]) ?>

            <p class="text-xs text-[#646464]">At 90% you are warned; at 100% new student accounts, uploads or generations (whichever is full) are paused. Nothing you already have is removed.</p>
        </section>

        <section class="flex flex-col gap-3" aria-labelledby="history-heading">
            <h2 id="history-heading" class="text-sm font-bold text-brand">History</h2>

            <?= view('components/data-table', [
                'caption'    => 'Subscription history',
                'columns'    => [
                    ['key' => 'recorded', 'label' => 'Recorded'],
                    ['key' => 'kind', 'label' => 'What'],
                    ['key' => 'plan', 'label' => 'Plan'],
                    ['key' => 'period', 'label' => 'Period'],
                    ['key' => 'amount', 'label' => 'Amount'],
                    ['key' => 'reference', 'label' => 'Reference'],
                ],
                'rows'       => $rows,
                'emptyIcon'  => 'info',
                'emptyTitle' => 'Nothing recorded yet',
                'emptyText'  => 'Each term of your subscription is listed here when it is recorded.',
            ], ['saveData' => false]) ?>

            <p class="text-xs text-[#646464]">Records are never changed or removed; a mistake is put right with a correction.</p>
        </section>

    <?php endif; ?>

</div>

</div>

<?= $this->endSection() ?>
