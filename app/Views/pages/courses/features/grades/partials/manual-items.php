<?php
/**
 * The scores a student's instructor entered by hand (attendance, participation,
 * class work...), grouped by category: the score out of the maximum, or
 * "Excused" when the student is let off an item. Shown once the grading is
 * published. Uses: manualItems (GradeService::buildStudentGrades).
 */
$manualItems = $manualItems ?? [];

$points = static fn ($value) => rtrim(rtrim(number_format((float) $value, 2), '0'), '.');

$byCategory = [];

foreach ($manualItems as $item) {
    $byCategory[$item['category']][] = $item;
}
?>

<?php if ($manualItems !== []): ?>

    <div class="flex flex-col gap-3" data-manual-items>

        <span class="uppercase font-bold text-xs tracking-wide text-[#767676]">Scores from your instructor</span>

        <?php foreach ($byCategory as $category => $items): ?>
            <div class="flex flex-col rounded-2xl border-[1px] border-[#A9A9A9]/50">

                <span class="border-b border-gray-100 px-5 py-3 text-sm font-bold text-[#646464]"><?= esc($category) ?></span>

                <ul class="divide-y divide-gray-100">
                    <?php foreach ($items as $item): ?>
                        <li class="flex items-center justify-between gap-4 px-5 py-3 text-sm" data-manual-item>
                            <span class="min-w-0 truncate text-[#1A1C1E]"><?= esc($item['name']) ?></span>

                            <?php if ($item['excused']): ?>
                                <span class="shrink-0 rounded-full bg-gray-100 px-3 py-0.5 text-xs font-bold text-[#646464]">Excused</span>
                            <?php elseif ($item['score'] !== null): ?>
                                <span class="shrink-0 font-bold text-brand"><?= esc($points($item['score'])) ?> / <?= esc($points($item['max'])) ?></span>
                            <?php else: ?>
                                <span class="shrink-0 text-xs text-[#767676]">Not graded yet</span>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>

            </div>
        <?php endforeach; ?>

    </div>

<?php endif; ?>
