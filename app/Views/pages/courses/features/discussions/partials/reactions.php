<?php
/*
| Read-only summary of who reacted how: one pill per emoji with a count.
| Reacting happens from the hover overlay. Params: counts [emoji => n],
| own (emoji|null).
*/
$summaryTotal = array_sum(array_map('intval', $counts));
?>

<div class="flex flex-wrap items-center gap-1.5 text-sm text-gray-500 <?= $summaryTotal > 0 ? '' : 'hidden' ?>" data-reaction-summary>
    <?php foreach ($counts as $emoji => $count): ?>
        <?php if ((int) $count > 0): ?>
            <span class="flex items-center gap-1 rounded-full px-2 py-0.5 <?= $own === $emoji ? 'bg-[#0040A6]/10 text-[#0040A6]' : 'bg-gray-100' ?>">
                <?= esc($emoji) ?> <?= (int) $count ?>
            </span>
        <?php endif; ?>
    <?php endforeach; ?>
</div>
