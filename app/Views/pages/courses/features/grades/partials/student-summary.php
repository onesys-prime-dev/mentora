<?php
/**
 * Three-card summary strip, matching the header cards on the assessments
 * page (same border/icon-bubble treatment) so grades reads as the same
 * feature area rather than a different page style.
 */
$finalGrade = $summary['overall_percentage'] ?? null;

$remarksMeta = match ($summary['remarks'] ?? null) {
    'Passed'     => ['label' => 'Passed',      'class' => 'bg-emerald-100 text-emerald-700', 'icon' => 'check'],
    'Failed'     => ['label' => 'Failed',      'class' => 'bg-[#FFDAD6] text-[#B3261E]',      'icon' => 'attention'],
    'Withdrawn'  => ['label' => 'Withdrawn',   'class' => 'bg-gray-100 text-gray-500',        'icon' => 'attention'],
    default      => ['label' => 'In Progress', 'class' => 'bg-[#FFF1D6] text-[#946200]',     'icon' => 'in-progress'],
};

$gradedCount = (int) ($summary['graded_count'] ?? 0);
$totalCount = (int) ($summary['total_count'] ?? 0);
?>

<div class="flex flex-1 gap-10">

    <div class="p-6 flex w-full items-center gap-5 border-[1px] border-[#A9A9A9]/50 rounded-2xl">

        <div class="h-12 w-12 rounded-[100%] bg-[#EEEEEE] flex justify-center items-center">
            <?= icon('points', 'h-6 w-6') ?>
        </div>

        <div class="flex flex-col gap-1">

            <span class="uppercase font-bold text-sm text-[#878787]">
                Overall Grade
            </span>

            <span class="font-bold text-xl text-[#0040A6]">
                <?= $finalGrade !== null ? esc(number_format((float) $finalGrade, 1)) . '%' : '—' ?>
            </span>

            <span class="text-xs text-[#878787]">
                <?= !empty($summary['is_override'])
                    ? 'Set by your instructor'
                    : ($finalGrade !== null ? 'From your graded work so far' : 'Nothing graded yet') ?>
            </span>

        </div>

    </div>

    <div class="p-6 flex w-full items-center gap-4 border-[1px] border-[#A9A9A9]/50 rounded-2xl">

        <div class="h-12 w-12 rounded-[100%] bg-[#EEEEEE] flex justify-center items-center">
            <?= icon('score', 'h-6 w-6') ?>
        </div>

        <div class="flex flex-col gap-1">

            <span class="uppercase font-bold text-sm text-[#878787]">
                Points Earned
            </span>

            <span class="font-bold text-xl text-[#0040A6]">
                <?= esc(round((float) ($summary['points_earned'] ?? 0), 2)) ?>/<?= esc(round((float) ($summary['points_possible'] ?? 0), 2)) ?>
            </span>

            <span class="text-xs text-[#878787]" data-grades-graded-count>
                <?= esc($gradedCount) ?> of <?= esc($totalCount) ?> graded
            </span>

        </div>

    </div>

    <div class="p-6 flex w-full items-center gap-4 border-[1px] border-[#A9A9A9]/50 rounded-2xl">

        <div class="h-12 w-12 rounded-[100%] <?= $remarksMeta['class'] ?> flex justify-center items-center">
            <?= icon($remarksMeta['icon'], 'h-6 w-6') ?>
        </div>

        <div class="flex flex-col gap-1">

            <span class="uppercase font-bold text-sm text-[#878787]">
                Standing
            </span>

            <span class="font-bold text-xl text-[#0040A6]">
                <?= esc($remarksMeta['label']) ?>
            </span>

        </div>

    </div>

</div>
