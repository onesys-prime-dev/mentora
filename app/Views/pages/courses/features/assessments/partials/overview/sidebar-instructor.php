<?php

/*
|--------------------------------------------------------------------------
| Instructor sidebar:
|  - Drafts (5): drafts due within a week first (students can't see them
|    yet), then the most recently edited. Opens the editor.
|  - Ready for Grading (5): assessments with section submissions waiting,
|    longest-waiting first. Opens Grade Work on the first waiting student.
|--------------------------------------------------------------------------
*/

$drafts = $overview['drafts'] ?? [];

$draftCount = (int) ($overview['draftCount'] ?? 0);

$ready = $overview['readyForGrading'] ?? [];

$toGradeTotal = (int) ($overview['toGradeTotal'] ?? 0);

$rowClasses = 'group flex cursor-pointer items-center justify-between gap-3 rounded-2xl border border-[#A9A9A9]/50 px-5 py-4 outline-none transition-all duration-200 hover:border-[#0040A6]/50 hover:bg-[#0040A6]/5 hover:shadow-sm focus-visible:border-[#0040A6] focus-visible:ring-2 focus-visible:ring-[#0040A6]/20';

$arrow = 'h-6 w-6 text-gray-500 transition duration-200 ease-out group-hover:text-[#0040A6] group-hover:translate-x-0.5 group-hover:scale-125 group-focus-visible:text-[#0040A6] group-focus-visible:scale-125';
?>

<!-- Drafts -->
<div class="flex flex-col gap-4">

    <div class="flex items-center justify-between gap-3">

        <div class="flex items-center gap-3">
            <?= icon('drafts', 'h-5 w-5') ?>
            <span class="text-xl font-bold text-[#0040A6]">Drafts</span>
            <?php if ($draftCount > 0): ?>
                <span class="rounded-full bg-gray-100 px-2.5 py-0.5 text-xs font-bold text-gray-500"><?= $draftCount ?></span>
            <?php endif; ?>
        </div>

        <?php if ($draftCount > 0): ?>
            <button
                type="button"
                data-assessments-filter-set="draft"
                class="text-sm font-semibold text-[#0040A6] transition hover:underline">
                View all
            </button>
        <?php endif; ?>

    </div>

    <div class="flex flex-col gap-2">

        <?php foreach ($drafts as $draft): ?>

            <div
                class="<?= $rowClasses ?>"
                role="link"
                tabindex="0"
                data-edit-assessment-button
                data-assessment="<?= esc($draft['id']) ?>">

                <div class="flex min-w-0 items-center gap-3">

                    <div class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full border-2 border-[#0040A6]">
                        <?= learning_item_icon($draft['assessment_type'], 'h-4 w-4 text-[#0040A6]') ?>
                    </div>

                    <div class="flex min-w-0 flex-col">

                        <span class="truncate font-bold text-[#646464]"><?= esc($draft['title']) ?></span>

                        <span class="text-xs text-[#878787]">
                            <?= esc(implode(' · ', array_filter([
                                $draft['edited'] ? 'Edited ' . $draft['edited'] : null,
                                $draft['is_quiz'] ? (int) $draft['question_count'] . ' ' . ((int) $draft['question_count'] === 1 ? 'question' : 'questions') : ucfirst((string) $draft['assessment_type']),
                            ]))) ?>
                        </span>

                        <?php if ($draft['dueWarning']): ?>
                            <span class="mt-1 w-fit rounded-full bg-red-50 px-2 py-0.5 text-[11px] font-bold text-red-600">
                                <?= esc($draft['dueWarning']) ?>
                            </span>
                        <?php endif; ?>

                    </div>

                </div>

                <?= icon('start', $arrow) ?>

            </div>

        <?php endforeach; ?>

        <?php if (!$drafts): ?>
            <p class="rounded-2xl border border-dashed border-gray-200 px-5 py-6 text-center text-sm text-gray-400">
                No drafts — everything is published.
            </p>
        <?php endif; ?>

    </div>

</div>

<!-- Ready for Grading -->
<div class="flex flex-col gap-4">

    <div class="flex items-center gap-3">
        <?= icon('tasks', 'h-5 w-5 text-[#0040A6]') ?>
        <span class="text-xl font-bold text-[#0040A6]">Ready for Grading</span>
        <?php if ($toGradeTotal > 0): ?>
            <span class="rounded-full bg-[#FFF1D6] px-2.5 py-0.5 text-xs font-bold text-[#946200]"><?= $toGradeTotal ?></span>
        <?php endif; ?>
    </div>

    <div class="flex flex-col gap-2">

        <?php foreach ($ready as $item): ?>

            <div
                class="<?= $rowClasses ?>"
                role="link"
                tabindex="0"
                data-grade-assessment="<?= esc($item['id']) ?>"
                data-grade-student="<?= esc($item['section']['firstWaitingStudent'] ?? '') ?>">

                <div class="flex min-w-0 items-center gap-3">

                    <div class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full border-2 border-[#0040A6]">
                        <?= learning_item_icon($item['assessment_type'], 'h-4 w-4 text-[#0040A6]') ?>
                    </div>

                    <div class="flex min-w-0 flex-col">

                        <span class="truncate font-bold text-[#646464]"><?= esc($item['title']) ?></span>

                        <span class="text-xs text-[#878787]">
                            <strong class="text-[#946200]"><?= (int) $item['section']['toGrade'] ?> to grade</strong>
                            <?= $item['waiting'] ? '· oldest waiting ' . esc($item['waiting']) : '' ?>
                        </span>

                    </div>

                </div>

                <?= icon('start', $arrow) ?>

            </div>

        <?php endforeach; ?>

        <?php if (!$ready): ?>
            <p class="rounded-2xl border border-dashed border-gray-200 px-5 py-6 text-center text-sm text-gray-400">
                Nothing waiting to be graded.
            </p>
        <?php endif; ?>

    </div>

</div>
