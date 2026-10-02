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

                <span class="text-lg font-semibold text-[#646464]">
                    <?= esc($currentAssessment['title']) ?>
                </span>

            </div>

        </div>

        <div class="flex items-center gap-4">

            <div
                data-builder-save-status
                class="flex items-center gap-2 text-sm text-gray-500">

                <div class="h-2 w-2 rounded-full bg-emerald-500" data-builder-save-indicator></div>

                <span data-builder-save-message>All changes saved</span>

            </div>

            <div class="h-5 w-px bg-gray-200"></div>

            <div class="relative">

                <button
                    type="button"
                    data-toggle-export-options
                    title="Export"
                    class="rounded-lg p-2 text-gray-500 transition hover:bg-gray-100 hover:text-[#0040A6]" aria-label="Export">

                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5M16.5 12L12 16.5m0 0L7.5 12m4.5 4.5V3" />
                    </svg>

                </button>

                <div
                    data-export-options-panel
                    class="absolute right-0 top-full z-20 mt-2 hidden w-56 flex-col gap-3 rounded-xl border border-gray-200 bg-white p-4 shadow-xl">

                    <span class="text-xs font-semibold uppercase tracking-wide text-gray-500">
                        Export format
                    </span>

                    <div class="flex flex-col gap-1">

                        <label class="flex items-center gap-2 rounded-lg px-2 py-1.5 text-sm text-gray-700 transition hover:bg-gray-50">
                            <input type="radio" name="export-format" value="docx" data-export-format-option checked class="h-4 w-4 accent-[#0040A6]">
                            Word document (.docx)
                        </label>

                        <label class="flex items-center gap-2 rounded-lg px-2 py-1.5 text-sm text-gray-700 transition hover:bg-gray-50">
                            <input type="radio" name="export-format" value="rtf" data-export-format-option class="h-4 w-4 accent-[#0040A6]">
                            Rich Text (.rtf)
                        </label>

                    </div>

                    <button
                        type="button"
                        data-export-questions
                        data-assessment-id="<?= esc($currentAssessment['id']) ?>"
                        class="rounded-xl bg-[#0040A6] px-3 py-2 text-xs font-semibold text-white transition hover:bg-[#002068]">

                        Export

                    </button>

                </div>

            </div>

            <button
                type="button"
                data-open-import-questions
                title="Import from Word"
                class="rounded-lg p-2 text-gray-500 transition hover:bg-gray-100 hover:text-[#0040A6]" aria-label="Import from Word">

                <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5M7.5 7.5L12 3m0 0l4.5 4.5M12 3v13.5" />
                </svg>

            </button>

            <button
                type="button"
                data-clear-all-questions
                title="Clear all questions"
                class="rounded-lg p-2 text-gray-500 transition hover:bg-red-50 hover:text-red-500" aria-label="Clear all questions">

                <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M4 7h16M9 7V5a2 2 0 012-2h2a2 2 0 012 2v2m3 0v12a2 2 0 01-2 2H8a2 2 0 01-2-2V7h12zM10 11v6M14 11v6" />
                </svg>

            </button>

        </div>

    </div>

    <div class="flex flex-1 items-start gap-6 py-6">

        <?= $this->include('pages/courses/features/assessments/partials/quiz-builder-palette') ?>

        <div class="flex min-w-0 flex-1 flex-col gap-3">

            <span class="rounded-full self-start bg-[#0040A6]/10 px-2.5 py-0.5 text-xs font-bold text-[#0040A6]" data-question-count-badge>
                <?= esc(count($currentQuestions ?? [])) ?> question<?= count($currentQuestions ?? []) === 1 ? '' : 's' ?> · <?= esc((int) ($currentAssessment['total_points'] ?? 0)) ?> pts total
            </span>

            <?= $this->include('pages/courses/features/assessments/partials/ai-review-tray') ?>

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

                    <span class="text-sm font-semibold text-[#646464]">
                        No questions yet
                    </span>

                    <span class="text-xs text-[#767676]">
                        Drag a question type from the left, or click one to add it here.
                    </span>

                </div>

            </div>

        </div>

    </div>

    <?= $this->include('pages/courses/features/assessments/partials/modals/question-settings') ?>
    <?= $this->include('pages/courses/features/assessments/partials/modals/question-delete') ?>
    <?= $this->include('pages/courses/features/assessments/partials/modals/questions-clear-all') ?>
    <?= $this->include('pages/courses/features/assessments/partials/modals/ai-generate') ?>
    <?= $this->include('pages/courses/features/assessments/partials/modals/ai-question-settings') ?>
    <?= $this->include('pages/courses/features/assessments/partials/modals/import-questions') ?>

</div>
