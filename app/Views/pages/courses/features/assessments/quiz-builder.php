<div class="flex min-w-0 flex-1 flex-col p-10">

    <div class="flex items-center justify-between border-b border-gray-200 pb-4">

        <div class="flex items-center gap-3">

            <button
                type="button"
                data-back-to-assessment-editor
                data-assessment-id="<?= esc($currentAssessment['id']) ?>"
                class="rounded-lg p-2 text-gray-500 transition hover:bg-gray-100">

                <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7" />
                </svg>

            </button>

            <div class="flex items-center gap-2">

                <span class="text-xl font-bold text-[#0040A6]">
                    Quiz Builder
                </span>

                <span class="text-gray-300">
                    ·
                </span>

                <span class="text-lg font-semibold text-[#5B5B5B]">
                    <?= esc($currentAssessment['title']) ?>
                </span>

            </div>

        </div>

        <div
            data-builder-save-status
            class="flex items-center gap-2 text-sm text-gray-500">

            <div class="h-2 w-2 rounded-full bg-emerald-500" data-builder-save-indicator></div>

            <span data-builder-save-message>All changes saved</span>

        </div>

    </div>

    <div class="flex flex-1 items-start gap-6 py-6">

        <?= $this->include('pages/courses/features/assessments/partials/quiz-builder-palette') ?>

        <div class="flex min-w-0 flex-1 flex-col gap-3">

            <span class="rounded-full self-start bg-[#0040A6]/10 px-2.5 py-0.5 text-xs font-bold text-[#0040A6]" data-question-count-badge>
                <?= esc(count($currentQuestions ?? [])) ?> question<?= count($currentQuestions ?? []) === 1 ? '' : 's' ?> · <?= esc((int) ($currentAssessment['total_points'] ?? 0)) ?> pts total
            </span>

            <div
                id="question-builder-container"
                data-assessment-id="<?= esc($currentAssessment['id']) ?>">

                <div
                    class="flex flex-col gap-3"
                    data-question-list>

                    <?php foreach (($currentQuestions ?? []) as $index => $question): ?>

                        <?= view(
                            'pages/courses/features/assessments/partials/quiz-builder-card',
                            ['question' => $question, 'index' => $index]
                        ) ?>

                    <?php endforeach; ?>

                </div>

                <div
                    class="<?= empty($currentQuestions) ? 'flex' : 'hidden' ?> flex-col items-center gap-2 rounded-2xl border border-dashed border-[#DBDBDB] bg-[#F9F9FC] p-10 text-center"
                    data-question-list-empty>

                    <span class="text-sm font-semibold text-[#5B5B5B]">
                        No questions yet
                    </span>

                    <span class="text-xs text-[#939393]">
                        Drag a question type from the left, or click one to add it here.
                    </span>

                </div>

            </div>

        </div>

    </div>

    <?= $this->include('pages/courses/features/assessments/partials/modals/question-settings') ?>
    <?= $this->include('pages/courses/features/assessments/partials/modals/question-delete') ?>

</div>
