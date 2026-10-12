<?php
/**
 * How a student's final grade is made up: each category's weight and the
 * student's percentage in it (GradingEngineService::compute). A category
 * with nothing graded yet shows "—" and doesn't count toward the grade.
 *
 * When the section uses grading periods, categories are grouped under a
 * period heading (with the period's own weight and percentage) instead of
 * one flat grid.
 */
$categoryBreakdown = $categoryBreakdown ?? [];
$periodBreakdown = $periodBreakdown ?? [];

$points = static fn ($value) => rtrim(rtrim(number_format((float) $value, 2), '0'), '.');

$categoryCard = static function (array $category) use ($points): string {
    ob_start(); ?>
    <div class="flex flex-col gap-1 rounded-2xl border-[1px] border-[#A9A9A9]/50 px-5 py-4">

        <div class="flex items-center justify-between gap-2">
            <span class="truncate font-bold text-[#646464]"><?= esc($category['name']) ?></span>
            <span class="shrink-0 rounded-full bg-gray-100 px-2 py-0.5 text-[10px] font-bold text-gray-600">
                <?= esc($points($category['weight'])) ?>%
            </span>
        </div>

        <span class="font-bold text-lg text-brand">
            <?= $category['percentage'] !== null ? esc(number_format($category['percentage'], 2)) . '%' : '—' ?>
        </span>

        <span class="text-xs text-[#767676]">
            <?= $category['graded_count'] > 0
                ? esc(round($category['earned'], 2)) . ' of ' . esc(round($category['possible'], 2)) . ' pts'
                : 'Nothing graded yet' ?>
        </span>

    </div>
    <?php return (string) ob_get_clean();
};
?>

<?php if ($periodBreakdown !== []): ?>

    <div class="flex flex-col gap-5" data-grade-categories data-uses-periods="1">

        <span class="uppercase font-bold text-xs tracking-wide text-[#767676]">
            How your grade is calculated
        </span>

        <?php foreach ($periodBreakdown as $period): ?>

            <div class="flex flex-col gap-3">

                <div class="flex items-center gap-3">
                    <span class="font-bold text-[#646464]"><?= esc($period['name']) ?></span>
                    <span class="rounded-full bg-gray-100 px-2 py-0.5 text-[10px] font-bold text-gray-600"><?= esc($points($period['weight'])) ?>%</span>
                    <span class="ml-auto font-bold text-brand">
                        <?= $period['percentage'] !== null ? esc(number_format($period['percentage'], 2)) . '%' : '—' ?>
                    </span>
                </div>

                <div class="grid grid-cols-2 gap-3 md:grid-cols-4">
                    <?php foreach ($period['categories'] as $category): ?>
                        <?= $categoryCard($category) ?>
                    <?php endforeach; ?>
                </div>

            </div>

        <?php endforeach; ?>

    </div>

<?php elseif ($categoryBreakdown !== []): ?>

    <div class="flex flex-col gap-3" data-grade-categories>

        <span class="uppercase font-bold text-xs tracking-wide text-[#767676]">
            How your grade is calculated
        </span>

        <div class="grid grid-cols-2 gap-3 md:grid-cols-4">
            <?php foreach ($categoryBreakdown as $category): ?>
                <?= $categoryCard($category) ?>
            <?php endforeach; ?>
        </div>

    </div>

<?php endif; ?>
