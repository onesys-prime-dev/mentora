<?php
/**
 * One grade category for a parent: its name, weight and the child's
 * percentage in it. No points, no items.
 *
 * @var array{name: string, weight: float|int|string, percentage: ?float} $category
 */
helper('grade');
?>

<div class="flex flex-col gap-1 rounded-2xl border border-[#A9A9A9]/50 px-5 py-4">

    <div class="flex items-center justify-between gap-2">
        <span class="truncate font-bold text-[#646464]"><?= esc($category['name']) ?></span>
        <span class="shrink-0 rounded-full bg-gray-100 px-2 py-0.5 text-[10px] font-bold text-gray-600"><?= esc(grade_number($category['weight'])) ?>%</span>
    </div>

    <?php if ($category['percentage'] !== null): ?>
        <span class="font-bold text-lg text-brand"><?= esc(grade_percent($category['percentage'])) ?></span>
    <?php else: ?>
        <span class="text-sm text-[#767676]">Nothing graded yet</span>
    <?php endif; ?>

</div>
