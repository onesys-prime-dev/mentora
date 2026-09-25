<?php

$previewLabels = [
    'Short Answer' => 'Students will type a short answer here.',
    'Essay' => 'Students will write a longer response here.',
    'Discussion' => 'Students will post a discussion response here.',
    'File Upload' => 'Students will upload a file here.',
];

$pointsValue = rtrim(rtrim(number_format((float) $question['points'], 2, '.', ''), '0'), '.');

if ($pointsValue === '') {
    $pointsValue = '0';
}

$allowMultipleAnswers = !empty($question['allow_multiple_answers']);
$answersCount = (int) ($question['answers_count'] ?? 2);
$caseSensitive = !empty($question['case_sensitive']);
?>

<div
    draggable="false"
    data-question-card
    data-question-id="<?= esc($question['id']) ?>"
    data-question-type="<?= esc($question['question_type']) ?>"
    data-question-allow-multiple-answers="<?= $allowMultipleAnswers ? '1' : '0' ?>"
    data-question-answers-count="<?= esc($answersCount) ?>"
    data-question-case-sensitive="<?= $caseSensitive ? '1' : '0' ?>"
    class="rounded-xl border-2 border-gray-100 bg-white p-4 shadow-sm transition">

    <div class="flex items-center gap-2 border-b border-gray-100 pb-3">

        <button
            type="button"
            data-drag-handle
            title="Drag to reorder"
            class="cursor-grab rounded p-1 text-gray-400 transition hover:bg-gray-100 active:cursor-grabbing">

            <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="currentColor" viewBox="0 0 24 24">
                <circle cx="9" cy="6" r="1.5" /><circle cx="15" cy="6" r="1.5" />
                <circle cx="9" cy="12" r="1.5" /><circle cx="15" cy="12" r="1.5" />
                <circle cx="9" cy="18" r="1.5" /><circle cx="15" cy="18" r="1.5" />
            </svg>

        </button>

        <span class="text-sm font-bold text-gray-400" data-question-number>
            Q<?= esc($index + 1) ?>
        </span>

        <span class="rounded-full bg-[#0040A6]/10 px-2.5 py-0.5 text-xs font-bold text-[#0040A6]" data-question-type-badge>
            <?= esc($question['question_type']) ?>
        </span>

        <input
            type="number"
            data-question-points-input
            min="0"
            step="0.5"
            value="<?= esc($pointsValue) ?>"
            class="w-14 rounded-lg border border-gray-200 px-1.5 py-0.5 text-xs text-center outline-none transition focus:border-[#0040A6]">
        <span class="text-[10px] text-gray-400">pts</span>

        <span
            class="rounded-full bg-red-50 px-2 py-0.5 text-[10px] font-bold text-red-500 <?= empty($question['is_required']) ? 'hidden' : '' ?>"
            data-question-required-badge>
            Required
        </span>

        <span
            class="rounded-full bg-[#0040A6]/10 px-2 py-0.5 text-[10px] font-bold text-[#0040A6] <?= $allowMultipleAnswers ? '' : 'hidden' ?>"
            data-question-multiple-badge>
            Select <?= esc($answersCount) ?>
        </span>

        <div class="ml-auto flex shrink-0 items-center gap-1">

            <button
                type="button"
                data-open-question-settings
                title="Question settings"
                class="rounded-lg p-1.5 text-gray-500 transition hover:bg-gray-100 hover:text-[#0040A6]">

                <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z" />
                    <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                </svg>

            </button>

            <button
                type="button"
                data-duplicate-question
                title="Duplicate question"
                class="rounded-lg p-1.5 text-gray-500 transition hover:bg-gray-100 hover:text-[#0040A6]">

                <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z" />
                </svg>

            </button>

            <button
                type="button"
                data-delete-question
                title="Delete question"
                class="rounded-lg p-1.5 text-gray-500 transition hover:bg-red-50 hover:text-red-500">

                <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M4 7h16M9 7V5a2 2 0 012-2h2a2 2 0 012 2v2m3 0v12a2 2 0 01-2 2H8a2 2 0 01-2-2V7h12zM10 11v6M14 11v6" />
                </svg>

            </button>

        </div>

    </div>

    <div class="flex flex-col gap-3 pt-3">

        <textarea
            rows="2"
            data-question-text
            placeholder="Type your question..."
            class="w-full resize-none rounded-lg border-0 bg-transparent px-1 py-1 text-sm font-medium text-[#1F2937] outline-none transition placeholder:text-gray-300 focus:bg-gray-50"
        ><?= esc($question['question_text']) ?></textarea>

        <?php if ($question['question_type'] === 'Multiple Choice'): ?>

            <div class="flex flex-col gap-2" data-choices-list>

                <?php foreach (($question['choices'] ?? []) as $choice):

                    $isCorrect = !empty($choice['is_correct']);
                ?>

                    <div
                        class="flex items-center gap-2 rounded-lg border px-2.5 py-1.5 transition-colors <?= $isCorrect ? 'border-emerald-300 bg-emerald-50' : 'border-gray-200' ?>"
                        data-choice-row
                        data-choice-id="<?= esc($choice['id']) ?>">

                        <input
                            type="<?= $allowMultipleAnswers ? 'checkbox' : 'radio' ?>"
                            data-choice-correct
                            <?= $allowMultipleAnswers ? '' : 'name="correct-choice-' . esc($question['id']) . '"' ?>
                            class="h-4 w-4 shrink-0 accent-emerald-600"
                            <?= $isCorrect ? 'checked' : '' ?>>

                        <input
                            type="text"
                            data-choice-text
                            value="<?= esc($choice['choice_text']) ?>"
                            placeholder="Choice text"
                            class="min-w-0 flex-1 border-0 bg-transparent px-1 py-0.5 text-sm outline-none transition <?= $isCorrect ? 'font-semibold text-emerald-700' : 'text-gray-700' ?>">

                        <button
                            type="button"
                            data-remove-choice
                            class="shrink-0 rounded-lg p-1 text-gray-300 transition hover:bg-red-50 hover:text-red-500">

                            <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                            </svg>

                        </button>

                    </div>

                <?php endforeach; ?>

            </div>

            <button
                type="button"
                data-add-choice-inline
                class="self-start text-xs font-semibold text-[#0040A6] transition hover:underline">

                + Add choice

            </button>

        <?php elseif ($question['question_type'] === 'Short Answer'): ?>

            <!-- Answer key: auto-grades the student's answer -->
            <?= view('pages/courses/features/assessments/partials/accepted-answers-editor', [
                'acceptedAnswers' => $question['accepted_answers'] ?? [],
                'caseSensitive' => $caseSensitive,
            ]) ?>

        <?php else: ?>

            <div class="rounded-lg border border-dashed border-gray-200 bg-gray-50 px-4 py-3 text-xs text-gray-400">
                <?= esc($previewLabels[$question['question_type']] ?? 'Students will respond here.') ?>
            </div>

        <?php endif; ?>

    </div>

</div>
