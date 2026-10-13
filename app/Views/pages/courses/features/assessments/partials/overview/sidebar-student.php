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

$rowClasses = 'group flex cursor-pointer items-center justify-between gap-3 rounded-2xl border border-[#A9A9A9]/50 px-5 py-4 outline-none transition-all duration-200 hover:border-brand/50 hover:bg-brand/5 hover:shadow-sm focus-visible:border-brand focus-visible:ring-2 focus-visible:ring-brand/20';

$arrow = 'h-6 w-6 text-gray-500 transition duration-200 ease-out group-hover:text-brand group-hover:translate-x-0.5 group-hover:scale-125 group-focus-visible:text-brand group-focus-visible:scale-125';
?>

<!-- Up Next -->
<div class="flex flex-col gap-4" data-sidebar-section="upnext">

    <div class="flex items-center gap-3">
        <?= icon('start', 'h-5 w-5') ?>
        <span class="text-xl font-bold text-brand">Up Next</span>
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

                    <div class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full border-2 border-brand">
                        <?= learning_item_icon($item['assessment_type'], 'h-4 w-4 text-brand') ?>
                    </div>

                    <div class="flex min-w-0 flex-col">
                        <span class="truncate font-bold text-[#646464]"><?= esc($item['title']) ?></span>
                        <span class="text-xs <?= in_array($state['key'], ['overdue', 'in_progress'], true) ? 'font-semibold text-[#946200]' : 'text-[#767676]' ?>">
                            <?= esc($item['nextDetail']) ?>
                        </span>
                    </div>

                </div>

                <?= icon('start', $arrow) ?>

            </div>

        <?php endforeach; ?>

        <?php if (!$upNext): ?>
            <p class="rounded-2xl border border-dashed border-gray-200 px-5 py-6 text-center text-sm text-[#767676]">
                You're all caught up.
            </p>
        <?php endif; ?>

    </div>

</div>

<!-- Feedback -->
<div class="flex flex-col gap-4" data-sidebar-section="feedback">

    <div class="flex items-center gap-3">
        <?= icon('feedbacks', 'h-5 w-5') ?>
        <span class="text-xl font-bold text-brand">Feedback</span>
    </div>

    <div class="flex flex-col gap-2">

        <?php foreach ($feedback as $entry): ?>

            <?php $item = $entry['assessment']; ?>

            <div
                class="group flex cursor-pointer flex-col gap-2 rounded-2xl border border-[#A9A9A9]/50 px-5 py-4 outline-none transition-all duration-200 hover:border-brand/50 hover:bg-brand/5 hover:shadow-sm focus-visible:border-brand focus-visible:ring-2 focus-visible:ring-brand/20"
                role="link"
                tabindex="0"
                data-assessment="<?= esc($item['id']) ?>">

                <div class="flex items-center justify-between gap-3">

                    <div class="flex min-w-0 items-center gap-3">

                        <div class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full border-2 border-brand">
                            <?= learning_item_icon($item['assessment_type'], 'h-4 w-4 text-brand') ?>
                        </div>

                        <span class="truncate font-bold text-[#646464]"><?= esc($item['title']) ?></span>

                    </div>

                    <span class="shrink-0 rounded-full bg-[#E8F5EC] px-2.5 py-0.5 text-xs font-bold text-[#2E7D4F]">
                        <?= (int) $entry['score'] ?>/<?= (int) $item['total_points'] ?>
                    </span>

                </div>

                <p class="line-clamp-3 text-sm text-[#646464]">
                    “<?= esc($entry['feedback']) ?>”
                </p>

                <div class="flex items-center justify-between gap-2">

                    <span class="text-xs text-[#767676]"><?= esc($entry['when']) ?></span>

                    <?php if ((int) $entry['message_count'] > 1): ?>
                        <span class="shrink-0 rounded-full bg-brand/10 px-2 py-0.5 text-[10px] font-bold text-brand">
                            <?= (int) $entry['message_count'] ?> messages
                        </span>
                    <?php endif; ?>

                </div>

            </div>

        <?php endforeach; ?>

        <?php if (!$feedback): ?>
            <p class="rounded-2xl border border-dashed border-gray-200 px-5 py-6 text-center text-sm text-[#767676]">
                Feedback from your instructor will show up here.
            </p>
        <?php endif; ?>

    </div>

</div>
