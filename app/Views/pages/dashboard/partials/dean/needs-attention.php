<?php
/**
 * The dean's "Needs attention": overrides to decide, sections to sign off or holding it up, follow-ups past
 * their day, and what is slipping in the department by sub-department, each opening the screen where it is
 * dealt with. Data: DeanAttentionRules::items() through DeanDashboardService::overview()['attention'].
 *
 * @var array{items: list<array{key: string, tone: string, count: int, title: string, detail: string, url: string}>} $data
 */
$items = $data['items'] ?? [];

$style = [
    'overrides'         => ['icon' => 'tasks',     'tint' => 'bg-[#FFF1D6]', 'color' => 'text-[#946200]', 'badge' => 'bg-[#FFF1D6] text-[#946200]'],
    'signoff_ready'     => ['icon' => 'check',     'tint' => 'bg-[#E8EEFC]', 'color' => 'text-[#0040A6]', 'badge' => 'bg-[#E8EEFC] text-[#0040A6]'],
    'signoff_blocked'   => ['icon' => 'lock',      'tint' => 'bg-[#FFF1D6]', 'color' => 'text-[#946200]', 'badge' => 'bg-[#FFF1D6] text-[#946200]'],
    'followups_overdue' => ['icon' => 'inbox',     'tint' => 'bg-[#FBE8E2]', 'color' => 'text-[#C3471F]', 'badge' => 'bg-[#FBE8E2] text-[#C3471F]'],
    'overdue_grading'   => ['icon' => 'tasks',     'tint' => 'bg-[#FFF1D6]', 'color' => 'text-[#946200]', 'badge' => 'bg-[#FFF1D6] text-[#946200]'],
    'inactive_courses'  => ['icon' => 'time-mono', 'tint' => 'bg-[#F1F3F8]', 'color' => 'text-[#4A5468]', 'badge' => 'bg-[#F1F3F8] text-[#4A5468]'],
    'at_risk'           => ['icon' => 'user',      'tint' => 'bg-[#FBE8E2]', 'color' => 'text-[#C3471F]', 'badge' => 'bg-[#FBE8E2] text-[#C3471F]'],
];
?>

<!-- needs attention (dean) -->
<div class="flex flex-col bg-white rounded-3xl shadow-sm overflow-clip h-full" data-widget="needs-attention">

    <div class="flex items-center justify-between px-6 py-4 border-b border-[#F1F3F8]">
        <div class="flex items-center gap-2.5">
            <?= icon('attention', 'w-[18px] h-[18px] text-[#C3471F]') ?>
            <h2 class="text-base font-bold text-[#0A0F1E]">Needs Attention</h2>
        </div>

        <?php if ($items !== []): ?>
            <span class="text-xs font-bold text-[#C3471F] bg-[#FBE8E2] rounded-full px-2.5 py-1">
                <?= count($items) ?> <?= count($items) === 1 ? 'item' : 'items' ?>
            </span>
        <?php endif; ?>
    </div>

    <?php if ($items === []): ?>

        <div class="flex flex-col items-center justify-center gap-1 text-center py-12" data-attention-empty>
            <span class="text-sm font-medium text-[#2A3142]">You're all caught up</span>
            <span class="text-xs text-[#6B7488]">Overrides to decide, sign-offs, overdue follow-ups and slipping courses will collect here.</span>
        </div>

    <?php else: ?>

        <div class="flex flex-col flex-1 min-h-0 overflow-y-auto scroll-area">

            <?php foreach ($items as $item): ?>

                <?php $look = $style[$item['key']] ?? $style['inactive_courses']; ?>

                <a
                    class="group flex items-center gap-3.5 px-6 py-4 border-b border-[#F6F7FA] last:border-b-0 transition-colors hover:bg-[#F8FAFF] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-brand/50"
                    href="<?= esc($item['url'], 'attr') ?>"
                    data-attention="<?= esc($item['key'], 'attr') ?>">

                    <span class="w-10 h-10 shrink-0 rounded-xl <?= $look['tint'] ?> <?= $look['color'] ?> flex items-center justify-center">
                        <?= icon($look['icon'], 'w-5 h-5') ?>
                    </span>

                    <span class="flex flex-col min-w-0 flex-1 gap-0.5">
                        <span class="text-sm font-semibold text-[#1A1C1E] truncate"><?= esc($item['title']) ?></span>
                        <span class="text-xs text-[#6B7488]"><?= esc($item['detail']) ?></span>
                    </span>

                    <span class="shrink-0 text-xs font-bold <?= $look['badge'] ?> rounded-full px-2.5 py-1 whitespace-nowrap">
                        <?= esc((string) $item['count']) ?>
                    </span>

                </a>

            <?php endforeach; ?>

        </div>

    <?php endif; ?>

</div>
