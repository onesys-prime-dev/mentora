<div class="flex flex-col gap-8">

    <!-- Quiz Builder -->
    <div class="flex flex-col gap-3">

        <div class="flex items-center gap-3">
            <?= icon('quiz', 'h-5 w-5') ?>

            <span class="font-bold text-lg text-[#0040A6]">
                Quiz Builder
            </span>
        </div>

        <div class="flex w-full min-h-100 flex-col items-center justify-center gap-5 rounded-2xl border border-[#DBDBDB] bg-linear-to-b from-[#F4F8FF] to-white px-10 py-12 text-center">

            <div class="flex h-16 w-16 items-center justify-center rounded-full bg-[#0040A6]/10">
                <?= icon('quiz', 'h-8 w-8') ?>
            </div>

            <div class="flex flex-col gap-1.5">

                <span class="text-lg font-bold text-[#1F2937]">
                    Build your quiz
                </span>

                <span class="text-sm text-[#6B7280]">
                    Add questions, set points, and mark the correct answers.
                </span>

            </div>

            <div class="flex items-center gap-6 text-sm text-[#5B5B5B]">

                <div class="flex items-center gap-1.5">
                    <?= icon('quiz', 'h-4 w-4 opacity-60') ?>
                    <span><?= esc(count($currentQuestions ?? [])) ?> question<?= count($currentQuestions ?? []) === 1 ? '' : 's' ?></span>
                </div>

                <div class="flex items-center gap-1.5">
                    <?= icon('work', 'h-4 w-4 opacity-60') ?>
                    <span><?= esc((int) ($currentAssessment['total_points'] ?? 0)) ?> points</span>
                </div>

            </div>

            <button
                type="button"
                data-enter-quiz-builder
                data-assessment-id="<?= esc($currentAssessment['id']) ?>"
                class="mt-2 inline-flex items-center gap-2 rounded-xl bg-[#0040A6] px-8 py-3 text-sm font-semibold text-white shadow-sm transition-colors hover:bg-[#002068]">

                Enter Quiz Builder

                <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7" />
                </svg>

            </button>

        </div>

        <?php if (!empty($currentQuestions)): ?>

            <div class="flex flex-col divide-y divide-[#EDEDED] rounded-2xl border border-[#DBDBDB] bg-white">

                <?php foreach ($currentQuestions as $index => $question): ?>

                    <div class="flex items-center justify-between gap-4 px-5 py-3.5">

                        <div class="flex min-w-0 items-center gap-3">

                            <span class="shrink-0 text-sm font-bold text-[#8A8A8A]">
                                Q<?= esc($index + 1) ?>
                            </span>

                            <span class="truncate text-sm text-[#5B5B5B]">
                                <?= esc($question['question_text']) ?>
                            </span>

                        </div>

                        <div class="flex shrink-0 items-center gap-2">

                            <?php if (!empty($question['is_required'])): ?>
                                <span class="text-xs font-semibold text-red-500">
                                    Required
                                </span>
                            <?php endif; ?>

                            <span class="rounded-full bg-gray-100 px-2.5 py-0.5 text-xs font-bold text-gray-600">
                                <?= esc((int) $question['points']) ?> pts
                            </span>

                            <span class="rounded-full bg-[#0040A6]/10 px-2.5 py-0.5 text-xs font-bold text-[#0040A6]">
                                <?= esc($question['question_type']) ?>
                            </span>

                        </div>

                    </div>

                <?php endforeach; ?>

            </div>

        <?php endif; ?>

    </div>

</div>
