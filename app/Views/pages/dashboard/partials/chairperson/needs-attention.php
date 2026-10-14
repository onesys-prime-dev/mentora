<?php
/**
 * The chairperson dashboard's "Needs Attention": overdue grading, at-risk
 * students and quiet courses in one prioritized list, instead of three
 * separate cards of mismatched length (the old layout's Grading Queue,
 * Needs Your Attention and Needs a Look). Data:
 * DashboardService::getNeedsAttentionData(), which reuses those same three
 * widgets' own methods rather than re-deriving anything.
 */
$userRole = esc(active_role() ?: ($user->getGroups()[0] ?? ''));

$items = $data['items'] ?? [];

$kindStyle = [
    'grading' => ['icon' => 'tasks',     'tint' => 'bg-[#FFF1D6]', 'color' => 'text-[#946200]', 'badge' => 'bg-[#FFF1D6] text-[#946200]'],
    'at_risk' => ['icon' => 'user',      'tint' => 'bg-[#FBE8E2]', 'color' => 'text-[#C3471F]', 'badge' => 'bg-[#FBE8E2] text-[#C3471F]'],
    'inactive' => ['icon' => 'time-mono', 'tint' => 'bg-[#F1F3F8]', 'color' => 'text-[#4A5468]', 'badge' => 'bg-[#F1F3F8] text-[#4A5468]'],
];

$href = static function (array $item) use ($userRole): string {
    return match ($item['kind']) {
        'grading' => site_url($userRole . '/courses/' . $item['offering_id'] . '/' . $item['slug'] . '/grades?assessment=' . $item['assessment_id'] . '&grade=1'),
        'at_risk' => site_url($userRole . '/courses/' . $item['offering_id'] . '/' . $item['slug'] . '/grades?roster=1&student=' . $item['student_id']),
        default   => site_url($userRole . '/courses/' . $item['offering_id'] . '/' . $item['slug']),
    };
};
?>

<!-- needs attention (chairperson) -->
<div class="flex flex-col bg-white rounded-3xl shadow-sm overflow-clip h-full">

    <div class="flex items-center justify-between px-6 py-4 border-b border-[#F1F3F8]">
        <div class="flex items-center gap-2.5">
            <?= icon('attention', 'w-[18px] h-[18px] text-[#C3471F]') ?>
            <span class="text-base font-bold text-[#0A0F1E]">Needs Attention</span>
        </div>

        <?php if ($items !== []): ?>
            <span class="text-xs font-bold text-[#C3471F] bg-[#FBE8E2] rounded-full px-2.5 py-1">
                <?= count($items) ?> <?= count($items) === 1 ? 'item' : 'items' ?>
            </span>
        <?php endif; ?>
    </div>

    <?php if ($items === []): ?>

        <div class="flex flex-col items-center justify-center gap-1 text-center py-12">
            <span class="text-sm font-medium text-[#2A3142]">You're all caught up</span>
            <span class="text-xs text-[#6B7488]">Grading, at-risk students and quiet courses will collect here.</span>
        </div>

    <?php else: ?>

        <!-- Fills the card; a longer list scrolls inside instead of growing it. -->
        <div class="flex flex-col flex-1 min-h-0 overflow-y-auto scroll-area">

            <?php foreach ($items as $item): ?>

                <?php $style = $kindStyle[$item['kind']] ?? $kindStyle['inactive']; ?>

                <a
                    class="group flex items-center gap-3.5 px-6 py-4 border-b border-[#F6F7FA] last:border-b-0 transition-colors hover:bg-[#F8FAFF]"
                    href="<?= $href($item) ?>">

                    <span class="w-10 h-10 shrink-0 rounded-xl <?= $style['tint'] ?> <?= $style['color'] ?> flex items-center justify-center">
                        <?= icon($style['icon'], 'w-5 h-5') ?>
                    </span>

                    <span class="flex flex-col min-w-0 flex-1 gap-0.5">
                        <span class="text-sm font-semibold text-[#1A1C1E] truncate"><?= esc($item['title']) ?></span>
                        <span class="text-xs text-[#6B7488]"><?= esc($item['subtitle']) ?></span>
                    </span>

                    <span class="shrink-0 text-xs font-bold <?= $style['badge'] ?> rounded-full px-2.5 py-1 whitespace-nowrap">
                        <?= esc($item['badge']) ?>
                    </span>

                </a>

            <?php endforeach; ?>

        </div>

    <?php endif; ?>

</div>
