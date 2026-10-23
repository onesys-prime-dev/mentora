<?php
/**
 * Chairperson dashboard's quick access as a compact icon strip instead of
 * a grid of large tiles — global_apps() is the only source now (see
 * DashboardService::getQuickAccessData()'s docblock), so this reads
 * straight from it rather than $data.
 */
$userRole = esc(active_role() ?: ($user->getGroups()[0] ?? ''));

$links = global_apps($userRole);
?>

<!-- quick access (chairperson) -->
<div class="flex items-center gap-2.5 overflow-x-auto">

    <?php foreach ($links as $link): ?>

        <?php if ($link['url'] === null): ?>

            <span
                class="shrink-0 flex items-center gap-2 h-10 px-4 rounded-full bg-white border border-[#E1E5EF] text-[13px] font-semibold text-[#A9A9A9] cursor-default"
                aria-disabled="true"
                title="Coming soon">
                <?= icon($link['icon'], 'w-4 h-4 text-[#A9A9A9]') ?>
                <?= esc($link['label']) ?>
            </span>

        <?php else: ?>

            <a
                class="shrink-0 flex items-center gap-2 h-10 px-4 rounded-full bg-white border border-[#E1E5EF] text-[13px] font-semibold text-[#2A3142] transition-colors hover:border-[#0040A6]/40 hover:bg-[#F8FAFF]"
                href="<?= esc($link['url'], 'attr') ?>">
                <?php // The colour is given to the icon itself: a few icons are registered in white (for blue tiles) and would vanish on this white pill. ?>
                <span class="flex"><?= icon($link['icon'], 'w-4 h-4 text-[#0040A6]') ?></span>
                <?= esc($link['label']) ?>
            </a>

        <?php endif; ?>

    <?php endforeach; ?>

</div>
