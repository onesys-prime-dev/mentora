<?php
/**
 * How a student's final grade is made up: each category's weight and the
 * student's percentage in it (GradingEngineService::compute). A category
 * with nothing graded yet shows "—" and doesn't count toward the grade.
 */
$categoryBreakdown = $categoryBreakdown ?? [];
?>

<?php if ($categoryBreakdown !== []): ?>

    <div class="flex flex-col gap-3" data-grade-categories>

        <span class="uppercase font-bold text-xs tracking-wide text-[#878787]">
            How your grade is calculated
        </span>

        <div class="grid grid-cols-2 gap-3 md:grid-cols-4">

            <?php foreach ($categoryBreakdown as $category): ?>

                <div class="flex flex-col gap-1 rounded-2xl border-[1px] border-[#A9A9A9]/50 px-5 py-4">

                    <div class="flex items-center justify-between gap-2">
                        <span class="truncate font-bold text-[#646464]"><?= esc($category['name']) ?></span>
                        <span class="shrink-0 rounded-full bg-gray-100 px-2 py-0.5 text-[10px] font-bold text-gray-600">
                            <?= esc(rtrim(rtrim(number_format($category['weight'], 2), '0'), '.')) ?>%
                        </span>
                    </div>

                    <span class="font-bold text-lg text-[#0040A6]">
                        <?= $category['percentage'] !== null ? esc(number_format($category['percentage'], 2)) . '%' : '—' ?>
                    </span>

                    <span class="text-xs text-[#878787]">
                        <?= $category['graded_count'] > 0
                            ? esc(round($category['earned'], 2)) . ' of ' . esc(round($category['possible'], 2)) . ' pts'
                            : 'Nothing graded yet' ?>
                    </span>

                </div>

            <?php endforeach; ?>

        </div>

    </div>

<?php endif; ?>
