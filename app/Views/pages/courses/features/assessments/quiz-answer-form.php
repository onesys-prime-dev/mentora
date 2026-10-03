<?php

$totalPoints = (float) ($currentAssessment['total_points'] ?? 0);

$previewLabels = [
    'Short Answer' => 'Type a short answer.',
    'Essay' => 'Write a longer response.',
];
?>

<div class="flex min-w-0 flex-1 flex-col p-10">

    <div class="flex items-center justify-between border-b border-gray-200 pb-4">

        <div class="flex items-center gap-3">

            <button
                type="button"
                data-back-to-assessment-view
                data-assessment-id="<?= esc($currentAssessment['id']) ?>"
                class="rounded-lg p-2 text-gray-500 transition hover:bg-gray-100">

                <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7" />
                </svg>

            </button>

            <div class="flex items-center gap-2">

                <span class="text-xl font-bold text-[#0040A6]">
                    Take Quiz
                </span>

                <span class="text-gray-300">
                    ·
                </span>

                <span class="text-lg font-semibold text-[#646464]">
                    <?= esc($currentAssessment['title']) ?>
                </span>

            </div>

        </div>

        <?php if (!$blocked && $submission && $submission['status'] === 'In Progress'): ?>

            <div class="flex items-center gap-4">

                <?php if (!empty($endsAt)): ?>

                    <div
                        data-quiz-timer
                        data-ends-at="<?= esc($endsAt) ?>"
                        class="flex items-center gap-1.5 rounded-full bg-[#FFF1D6] px-3 py-1.5 text-sm font-bold text-[#946200]">

                        <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                        </svg>

                        <span data-quiz-timer-value>--:--</span>

                    </div>

                <?php endif; ?>

                <div
                    data-take-quiz-save-status
                    class="flex items-center gap-2 text-sm text-gray-500">

                    <div class="h-2 w-2 rounded-full bg-emerald-500" data-take-quiz-save-indicator></div>

                    <span data-take-quiz-save-message>All changes saved</span>

                </div>

            </div>

        <?php endif; ?>

    </div>

    <?php if (!empty($lock)): ?>

        <div class="flex flex-1 flex-col items-center justify-center gap-3 py-24 text-center">

            <?= icon('lock', 'h-10 w-10') ?>

            <span class="text-lg font-bold text-[#646464]">This quiz is locked</span>

            <span class="text-sm text-[#767676]">
                <?= esc($lock['message']) ?> You'll find them in the Modules tab.
            </span>

        </div>

    <?php elseif (!empty($unavailableReason)): ?>

        <div class="flex flex-1 flex-col items-center justify-center gap-2 py-24 text-center">

            <span class="text-lg font-bold text-[#646464]">This quiz isn't available</span>

            <span class="text-sm text-[#767676]"><?= esc($unavailableReason) ?></span>

        </div>

    <?php elseif ($blocked): ?>

        <div class="flex flex-1 flex-col items-center justify-center gap-2 py-24 text-center">

            <span class="text-lg font-bold text-[#646464]">No attempts remaining</span>

            <span class="text-sm text-[#767676]">
                You've used all <?= esc((int) ($currentAssessment['attempt_limit'] ?? 1)) ?> allowed attempt<?= (int) ($currentAssessment['attempt_limit'] ?? 1) === 1 ? '' : 's' ?> for this quiz.
            </span>

        </div>

    <?php elseif (!$submission || $submission['status'] !== 'In Progress'): ?>

        <div class="flex flex-1 flex-col items-center justify-center gap-2 py-24 text-center">

            <span class="text-lg font-bold text-[#646464]">This attempt has already been submitted</span>

            <span class="text-sm text-[#767676]">Check the assessment view for your score once it's graded.</span>

        </div>

    <?php else: ?>

        <div class="flex flex-1 items-start gap-6 py-6">

            <div class="mx-auto flex w-full max-w-3xl flex-col gap-6">

                <!-- Progress -->
                <div class="flex flex-col gap-2 rounded-2xl border border-gray-100 bg-[#F9F9FC] p-4">

                    <div class="flex items-center justify-between text-sm">

                        <span class="font-semibold text-[#1A1C1E]" data-quiz-progress-label>
                            0 of <?= esc(count($questions)) ?> answered
                        </span>

                        <span class="font-bold text-[#0040A6]">
                            <?= esc((int) $totalPoints) ?> pts total
                        </span>

                    </div>

                    <div class="h-2 w-full overflow-hidden rounded-full bg-gray-200">
                        <div
                            data-quiz-progress-bar
                            class="h-full w-0 rounded-full bg-[#0040A6] transition-all"></div>
                    </div>

                </div>

                <form id="quiz-answer-form" data-submission-id="<?= esc($submission['id']) ?>">

                    <div class="flex flex-col gap-5">

                        <?php foreach ($questions as $index => $question): ?>

                            <?php
                                $response = $responsesByQuestion[$question['id']] ?? null;
                                $isMultipleAnswer = $question['question_type'] === 'Multiple Choice'
                                    && !empty($question['allow_multiple_answers']);
                                $answersCount = (int) ($question['answers_count'] ?? 2);
                                $isAnswered = $response && (
                                    $response['selected_choice_id'] !== null
                                    || !empty($response['selected_choice_ids'])
                                    || !empty($response['response_text'])
                                );
                            ?>

                            <div
                                data-quiz-question
                                data-question-id="<?= esc($question['id']) ?>"
                                data-answered="<?= $isAnswered ? '1' : '0' ?>"
                                data-multiple-answer="<?= $isMultipleAnswer ? '1' : '0' ?>"
                                data-answers-count="<?= esc($answersCount) ?>"
                                class="flex flex-col gap-4 rounded-2xl border border-gray-200 bg-white p-6 shadow-sm">

                                <div class="flex items-start justify-between gap-4">

                                    <div class="flex items-center gap-2">

                                        <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-[#0040A6]/10 text-xs font-bold text-[#0040A6]">
                                            <?= esc($index + 1) ?>
                                        </span>

                                        <p class="text-base font-semibold text-[#1A1C1E]">
                                            <?= esc($question['question_text']) ?>
                                            <?php if (!empty($question['is_required'])): ?>
                                                <span class="text-red-500">*</span>
                                            <?php endif; ?>
                                        </p>

                                    </div>

                                    <span class="shrink-0 rounded-full bg-gray-100 px-2.5 py-0.5 text-xs font-bold text-gray-600">
                                        <?= esc((int) $question['points']) ?> pts
                                    </span>

                                </div>

                                <?php if ($question['question_type'] === 'Multiple Choice'): ?>

                                    <?php if ($isMultipleAnswer): ?>

                                        <span class="pl-9 text-xs font-semibold text-[#0040A6]">
                                            Select <?= esc($answersCount) ?> answer<?= $answersCount === 1 ? '' : 's' ?>
                                        </span>

                                    <?php endif; ?>

                                    <div class="flex flex-col gap-2.5 pl-9" data-quiz-choices>

                                        <?php foreach ($question['choices'] as $choice): ?>

                                            <?php
                                                $isSelected = $isMultipleAnswer
                                                    ? ($response && in_array((int) $choice['id'], array_map('intval', $response['selected_choice_ids'] ?? []), true))
                                                    : ($response && (int) $response['selected_choice_id'] === (int) $choice['id']);
                                            ?>

                                            <label
                                                data-quiz-choice-row
                                                class="flex cursor-pointer items-center gap-3 rounded-xl border-2 px-4 py-3 text-sm font-medium transition-colors
                                                    <?= $isSelected ? 'border-[#0040A6] bg-[#0040A6]/5 text-[#0040A6]' : 'border-gray-200 text-gray-700 hover:border-[#0040A6]/40 hover:bg-gray-50' ?>">

                                                <input
                                                    type="<?= $isMultipleAnswer ? 'checkbox' : 'radio' ?>"
                                                    <?= $isMultipleAnswer ? '' : 'name="question-' . esc($question['id']) . '"' ?>
                                                    value="<?= esc($choice['id']) ?>"
                                                    data-quiz-choice-input
                                                    class="h-4 w-4 shrink-0 accent-[#0040A6]"
                                                    <?= $isSelected ? 'checked' : '' ?>>

                                                <?= esc($choice['choice_text']) ?>

                                            </label>

                                        <?php endforeach; ?>

                                    </div>

                                <?php elseif ($question['question_type'] === 'File Upload'): ?>

                                    <div class="ml-9 rounded-xl border border-dashed border-gray-200 bg-gray-50 px-4 py-6 text-center text-sm text-[#767676]">
                                        File upload isn't available yet for this quiz.
                                    </div>

                                <?php else: ?>

                                    <textarea
                                        rows="4"
                                        data-quiz-text-input
                                        placeholder="<?= esc($previewLabels[$question['question_type']] ?? 'Type your answer...') ?>"
                                        class="ml-9 rounded-xl border border-gray-200 p-3 text-sm outline-none transition focus:border-[#0040A6]"
                                    ><?= esc($response['response_text'] ?? '') ?></textarea>

                                <?php endif; ?>

                            </div>

                        <?php endforeach; ?>

                        <?php if (empty($questions)): ?>

                            <div class="flex flex-col items-center gap-2 rounded-2xl border border-dashed border-gray-200 bg-[#F9F9FC] p-10 text-center">
                                <span class="text-sm font-semibold text-[#646464]">This quiz has no questions yet.</span>
                            </div>

                        <?php endif; ?>

                    </div>

                    <div class="sticky bottom-0 mt-6 flex items-center justify-end gap-3 rounded-2xl border border-gray-200 bg-white/95 p-4 backdrop-blur">

                        <button
                            type="button"
                            data-submit-quiz
                            class="inline-flex items-center gap-2 rounded-xl bg-[#0040A6] px-8 py-3 text-sm font-semibold text-white shadow-sm transition-colors hover:bg-[#002068]">

                            Submit Quiz

                        </button>

                    </div>

                </form>

            </div>

        </div>

    <?php endif; ?>

</div>
