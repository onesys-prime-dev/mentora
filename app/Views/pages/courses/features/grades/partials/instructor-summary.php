<?php
/**
 * Same three-card header shape as the student view, different numbers:
 * an instructor's first question is "how's the class doing", not "how am
 * I doing" — class average, who's passing, who needs a look.
 */
$classAverage = $classSummary['average_percentage'] ?? null;
$passingCount = (int) ($classSummary['passing_count'] ?? 0);
$totalStudents = (int) ($classSummary['total_students'] ?? 0);
$needsReview = (int) ($classSummary['needs_review'] ?? 0);
?>

<div class="flex flex-1 gap-10">

    <div class="p-6 flex w-full items-center gap-5 border-[1px] border-[#A9A9A9]/50 rounded-2xl">

        <div class="h-12 w-12 rounded-[100%] bg-[#EEEEEE] flex justify-center items-center">
            <?= icon('points', 'h-6 w-6') ?>
        </div>

        <div class="flex flex-col gap-1">

            <span class="uppercase font-bold text-sm text-[#878787]">
                Class Average
            </span>

            <span class="font-bold text-xl text-[#0040A6]">
                <?= $classAverage !== null ? esc(number_format((float) $classAverage, 1)) . '%' : '—' ?>
            </span>

        </div>

    </div>

    <div class="p-6 flex w-full items-center gap-4 border-[1px] border-[#A9A9A9]/50 rounded-2xl">

        <div class="h-12 w-12 rounded-[100%] bg-[#EEEEEE] flex justify-center items-center">
            <?= icon('check', 'h-6 w-6') ?>
        </div>

        <div class="flex flex-col gap-1">

            <span class="uppercase font-bold text-sm text-[#878787]">
                Passing
            </span>

            <span class="font-bold text-xl text-[#0040A6]">
                <?= esc($passingCount) ?>/<?= esc($totalStudents) ?> Students
            </span>

        </div>

    </div>

    <div class="p-6 flex w-full items-center gap-4 border-[1px] border-[#A9A9A9]/50 rounded-2xl">

        <div class="h-12 w-12 rounded-[100%] <?= $needsReview > 0 ? 'bg-[#FFDAD6]' : 'bg-[#EEEEEE]' ?> flex justify-center items-center">
            <?= icon('attention', 'h-6 w-6') ?>
        </div>

        <div class="flex flex-col gap-1">

            <span class="uppercase font-bold text-sm text-[#878787]">
                Needs Review
            </span>

            <span class="font-bold text-xl text-[#0040A6]">
                <?= esc($needsReview) ?> Submission<?= $needsReview === 1 ? '' : 's' ?>
            </span>

        </div>

    </div>

</div>
