<?php

$readonly = $readonly ?? false;

$typeBadgeColors = match ($question['question_type']) {
    'Multiple Choice' => 'bg-[#E0E5F2] text-[#002068]',
    'Essay' => 'bg-[#FFF1D6] text-[#946200]',
    'Short Answer' => 'bg-[#E6F4EA] text-[#1E7B34]',
    'Discussion' => 'bg-[#F3E8FF] text-[#6B21A8]',
    'File Upload' => 'bg-[#FEE2E2] text-[#B91C1C]',
    default => 'bg-gray-100 text-gray-600',
};
?>

<div
    class="flex flex-col gap-3 rounded-2xl border border-[#DBDBDB] bg-white p-5"
    data-question-card
    data-question-id="<?= esc($question['id']) ?>">

    <div class="flex items-start justify-between gap-3">

        <div class="flex flex-wrap items-center gap-2">

            <span class="text-sm font-bold text-[#5B5B5B]">
                Q<?= esc($index + 1) ?>
            </span>

            <span class="rounded-full px-2.5 py-0.5 text-xs font-bold <?= $typeBadgeColors ?>">
                <?= esc($question['question_type']) ?>
            </span>

            <span class="rounded-full bg-gray-100 px-2.5 py-0.5 text-xs font-bold text-gray-600">
                <?= esc((int) $question['points']) ?> pts
            </span>

            <?php if (!empty($question['is_required'])): ?>
                <span class="text-xs font-semibold text-red-500">
                    Required
                </span>
            <?php endif; ?>

        </div>

        <?php if (!$readonly): ?>

            <div class="flex shrink-0 items-center gap-1">

                <button
                    type="button"
                    data-edit-question
                    data-question-id="<?= esc($question['id']) ?>"
                    class="rounded-lg p-1.5 text-gray-500 transition hover:bg-gray-100 hover:text-[#0040A6]">

                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5M16.5 3.5a2.121 2.121 0 113 3L12 14l-4 1 1-4 7.5-7.5z" />
                    </svg>

                </button>

                <button
                    type="button"
                    data-delete-question
                    data-question-id="<?= esc($question['id']) ?>"
                    class="rounded-lg p-1.5 text-gray-500 transition hover:bg-red-50 hover:text-red-500">

                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M4 7h16M9 7V5a2 2 0 012-2h2a2 2 0 012 2v2m3 0v12a2 2 0 01-2 2H8a2 2 0 01-2-2V7h12zM10 11v6M14 11v6" />
                    </svg>

                </button>

            </div>

        <?php endif; ?>

    </div>

    <p class="text-sm text-[#5B5B5B]">
        <?= esc($question['question_text']) ?>
    </p>

    <?php if ($question['question_type'] === 'Multiple Choice' && !empty($question['choices'])): ?>

        <div class="flex flex-col gap-1.5 pl-1">

            <?php foreach ($question['choices'] as $choice): ?>

                <div class="flex items-center gap-2 text-sm">

                    <span class="flex h-4 w-4 shrink-0 items-center justify-center rounded-full border <?= !empty($choice['is_correct']) ? 'border-[#0040A6] bg-[#0040A6]' : 'border-gray-300' ?>">
                        <?php if (!empty($choice['is_correct'])): ?>
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-2.5 w-2.5 text-white" fill="currentColor" viewBox="0 0 20 20">
                                <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd" />
                            </svg>
                        <?php endif; ?>
                    </span>

                    <span class="<?= !empty($choice['is_correct']) ? 'font-semibold text-[#0040A6]' : 'text-[#5B5B5B]' ?>">
                        <?= esc($choice['choice_text']) ?>
                    </span>

                </div>

            <?php endforeach; ?>

        </div>

    <?php endif; ?>

</div>
