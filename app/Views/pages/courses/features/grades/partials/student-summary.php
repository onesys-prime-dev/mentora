<?php
/**
 * Three-card summary strip, matching the header cards on the assessments
 * page (same border/icon-bubble treatment) so grades reads as the same
 * feature area rather than a different page style.
 */
$finalGrade = $summary['overall_percentage'] ?? null;

$remarksMeta = match ($summary['remarks'] ?? null) {
    'Passed'     => ['label' => 'Passed',     'class' => 'bg-emerald-100 text-emerald-700', 'icon' => 'check-green.png'],
    'Failed'     => ['label' => 'Failed',     'class' => 'bg-[#FFDAD6] text-[#B3261E]',      'icon' => 'attention.png'],
    'Withdrawn'  => ['label' => 'Withdrawn',  'class' => 'bg-gray-100 text-gray-500',        'icon' => 'attention.png'],
    default      => ['label' => 'In Progress', 'class' => 'bg-[#FFF1D6] text-[#946200]',     'icon' => 'in-progress.png'],
};
?>

<div class="flex flex-1 gap-10">

    <div class="p-6 flex w-full items-center gap-5 border-[1px] border-[#A9A9A9]/50 rounded-2xl">

        <div class="h-12 w-12 rounded-[100%] bg-[#EEEEEE] flex justify-center items-center">
            <img class="h-6 w-6" src="<?= base_url('assets/images/icons/points.png') ?>" alt="">
        </div>

        <div class="flex flex-col gap-1">

            <span class="uppercase font-bold text-sm text-[#878787]">
                Overall Grade
            </span>

            <span class="font-bold text-xl text-[#0040A6]">
                <?= $finalGrade !== null ? esc(number_format((float) $finalGrade, 1)) . '%' : 'Not yet graded' ?>
            </span>

        </div>

    </div>

    <div class="p-6 flex w-full items-center gap-4 border-[1px] border-[#A9A9A9]/50 rounded-2xl">

        <div class="h-12 w-12 rounded-[100%] bg-[#EEEEEE] flex justify-center items-center">
            <img class="h-6 w-6" src="<?= base_url('assets/images/icons/score.png') ?>" alt="">
        </div>

        <div class="flex flex-col gap-1">

            <span class="uppercase font-bold text-sm text-[#878787]">
                Points Earned
            </span>

            <span class="font-bold text-xl text-[#0040A6]">
                <?= esc((int) ($summary['points_earned'] ?? 0)) ?>/<?= esc((int) ($summary['points_possible'] ?? 0)) ?>
            </span>

        </div>

    </div>

    <div class="p-6 flex w-full items-center gap-4 border-[1px] border-[#A9A9A9]/50 rounded-2xl">

        <div class="h-12 w-12 rounded-[100%] <?= $remarksMeta['class'] ?> flex justify-center items-center">
            <img class="h-6 w-6" src="<?= base_url('assets/images/icons/' . $remarksMeta['icon']) ?>" alt="">
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
