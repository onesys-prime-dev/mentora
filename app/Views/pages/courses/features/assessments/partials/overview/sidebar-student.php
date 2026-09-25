<?php

/*
|--------------------------------------------------------------------------
| Student sidebar:
|  - Up Next (5): quizzes in progress first (click resumes the attempt),
|    then open work by due date — late-but-accepted first. Locked and
|    not-yet-open work is left out.
|  - Feedback (3): the latest graded work that has written feedback.
|--------------------------------------------------------------------------
*/

$upNext = $overview['upNext'] ?? [];

$feedback = $overview['feedback'] ?? [];

$rowClasses = 'group flex cursor-pointer items-center justify-between gap-3 rounded-2xl border border-[#A9A9A9]/50 px-5 py-4 outline-none transition-all duration-200 hover:border-[#0040A6]/50 hover:bg-[#0040A6]/5 hover:shadow-sm focus-visible:border-[#0040A6] focus-visible:ring-2 focus-visible:ring-[#0040A6]/20';

$arrow = 'h-6 w-6 text-gray-500 transition duration-200 ease-out group-hover:text-[#0040A6] group-hover:translate-x-0.5 group-hover:scale-125 group-focus-visible:text-[#0040A6] group-focus-visible:scale-125';
?>

<!-- Up Next -->
<div class="flex flex-col gap-4">

    <div class="flex items-center gap-3">
        <?= icon('start', 'h-5 w-5') ?>
        <span class="text-xl font-bold text-[#0040A6]">Up Next</span>
    </div>

    <div class="flex flex-col gap-2">

        <?php foreach ($upNext as $item): ?>

            <?php
                $state = $item['student'];

                $resume = $state['key'] === 'in_progress';
            ?>

            <div
                class="<?= $rowClasses ?>"
                role="link"
                tabindex="0"
                <?= $resume ? 'data-take-assessment' : 'data-assessment' ?>="<?= esc($item['id']) ?>">

                <div class="flex min-w-0 items-center gap-3">

                    <div class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full border-2 border-[#0040A6]">
                        <?= learning_item_icon($item['assessment_type'], 'h-4 w-4 text-[#0040A6]') ?>
                    </div>

                    <div class="flex min-w-0 flex-col">
                        <span class="truncate font-bold text-[#646464]"><?= esc($item['title']) ?></span>
                        <span class="text-xs <?= in_array($state['key'], ['overdue', 'in_progress'], true) ? 'font-semibold text-[#946200]' : 'text-[#878787]' ?>">
                            <?= esc($item['nextDetail']) ?>
                        </span>
                    </div>

                </div>

                <?= icon('start', $arrow) ?>

            </div>

        <?php endforeach; ?>

        <?php if (!$upNext): ?>
            <p class="rounded-2xl border border-dashed border-gray-200 px-5 py-6 text-center text-sm text-gray-400">
                You're all caught up.
            </p>
        <?php endif; ?>

    </div>

</div>

<!-- Feedback -->
<div class="flex flex-col gap-4">

    <div class="flex items-center gap-3">
        <?= icon('feedbacks', 'h-5 w-5') ?>
        <span class="text-xl font-bold text-[#0040A6]">Feedback</span>
    </div>

    <div class="flex flex-col gap-2">

        <?php foreach ($feedback as $entry): ?>

            <?php $item = $entry['assessment']; ?>

            <div
                class="group flex cursor-pointer flex-col gap-2 rounded-2xl border border-[#A9A9A9]/50 px-5 py-4 outline-none transition-all duration-200 hover:border-[#0040A6]/50 hover:bg-[#0040A6]/5 hover:shadow-sm focus-visible:border-[#0040A6] focus-visible:ring-2 focus-visible:ring-[#0040A6]/20"
                role="link"
                tabindex="0"
                data-assessment="<?= esc($item['id']) ?>">

                <div class="flex items-center justify-between gap-3">

                    <div class="flex min-w-0 items-center gap-3">

                        <div class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full border-2 border-[#0040A6]">
                            <?= learning_item_icon($item['assessment_type'], 'h-4 w-4 text-[#0040A6]') ?>
                        </div>

                        <span class="truncate font-bold text-[#646464]"><?= esc($item['title']) ?></span>

                    </div>

                    <span class="shrink-0 rounded-full bg-emerald-50 px-2.5 py-0.5 text-xs font-bold text-emerald-700">
                        <?= (int) $entry['score'] ?>/<?= (int) $item['total_points'] ?>
                    </span>

                </div>

                <p class="line-clamp-3 text-sm text-[#5B5B5B]">
                    “<?= esc($entry['feedback']) ?>”
                </p>

                <span class="text-xs text-gray-400"><?= esc($entry['when']) ?></span>

            </div>

        <?php endforeach; ?>

        <?php if (!$feedback): ?>
            <p class="rounded-2xl border border-dashed border-gray-200 px-5 py-6 text-center text-sm text-gray-400">
                Feedback from your instructor will show up here.
            </p>
        <?php endif; ?>

    </div>

</div>
