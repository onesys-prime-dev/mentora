<div class="flex flex-col gap-8">

    <!-- Instructions -->
    <div class="flex flex-col gap-3">

        <div class="flex items-center gap-3">
            <?= icon('info', 'h-5 w-5') ?>

            <span class="font-bold text-lg text-brand">
                Instructions
            </span>
        </div>

        <div
            id="instructions-paragraph-container"
            class="flex flex-col gap-3 rounded-2xl border border-gray-200 bg-white p-5">

            <?= $this->include('pages/courses/features/assessments/partials/instructions-paragraph') ?>

            <div class="flex items-center justify-end">

                <button
                    type="button"
                    class="inline-flex shrink-0 items-center gap-1.5 rounded-lg px-3 py-1.5 text-sm font-semibold text-brand transition-colors
                        hover:bg-brand/10"
                    data-edit-instructions>

                    <svg
                        xmlns="http://www.w3.org/2000/svg"
                        class="h-4 w-4"
                        fill="none"
                        viewBox="0 0 24 24"
                        stroke="currentColor"
                        stroke-width="2">

                        <path
                            stroke-linecap="round"
                            stroke-linejoin="round"
                            d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5M16.5 3.5a2.121 2.121 0 113 3L12 14l-4 1 1-4 7.5-7.5z" />

                    </svg>

                    Edit

                </button>

            </div>

        </div>

        <div
            id="instructions-editor-container"
            class="hidden flex-col">

            <?= $this->include('pages/courses/features/assessments/partials/instructions-editor') ?>

        </div>

    </div>

    <!-- Grading Rubrics -->
    <div class="flex flex-col gap-3">

        <div class="flex items-center gap-3">
            <?= icon('info', 'h-5 w-5') ?>

            <span class="font-bold text-lg text-brand">
                Grading Rubrics
            </span>
        </div>

        <div
            id="rubric-editor-container"
            class="flex flex-col"
            data-assessment-id="<?= esc($currentAssessment['id']) ?>">

            <div
                class="<?= $currentRubric ? 'hidden' : 'flex' ?> h-40 gap-3"
                data-rubric-options>

                <button
                    type="button"
                    class="flex flex-1 flex-col items-center justify-center gap-2 rounded-xl border border-[#DBDBDB] bg-[#F9F9FC]
                           text-sm font-semibold text-[#646464] transition-colors
                        hover:border-brand hover:bg-brand/5 hover:text-brand"
                    data-use-existing-rubric>

                    <svg
                        xmlns="http://www.w3.org/2000/svg"
                        class="h-6 w-6"
                        fill="none"
                        viewBox="0 0 24 24"
                        stroke="currentColor"
                        stroke-width="1.75">

                        <path
                            stroke-linecap="round"
                            stroke-linejoin="round"
                            d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />

                    </svg>

                    Use Existing Rubric

                </button>

                <button
                    type="button"
                    class="flex flex-1 flex-col items-center justify-center gap-2 rounded-xl border border-[#DBDBDB] bg-[#F9F9FC]
                           text-sm font-semibold text-[#646464] transition-colors
                        hover:border-brand hover:bg-brand/5 hover:text-brand"
                    data-create-new-rubric>

                    <svg
                        xmlns="http://www.w3.org/2000/svg"
                        class="h-6 w-6"
                        fill="none"
                        viewBox="0 0 24 24"
                        stroke="currentColor"
                        stroke-width="1.75">

                        <path
                            stroke-linecap="round"
                            stroke-linejoin="round"
                            d="M12 5v14M5 12h14" />

                    </svg>

                    Create New Rubric

                </button>

            </div>

            <div
                class="<?= $currentRubric ? 'flex' : 'hidden' ?> relative flex-col gap-3 rounded-2xl border border-[#DBDBDB] bg-[#fcfcfc] p-5"
                data-rubric-container>

                <button
                    type="button"
                    title="Remove rubric" aria-label="Remove rubric"
                    class="absolute -right-3 -top-3 flex h-7 w-7 items-center justify-center rounded-full border border-[#DBDBDB] bg-white text-[#646464] shadow-sm transition-colors
                        hover:bg-brand hover:text-white hover:border-brand"
                    data-unselect-rubric
                    data-rubric-id="<?= esc($currentRubric['id'] ?? '') ?>">

                    <svg
                        xmlns="http://www.w3.org/2000/svg"
                        class="h-4 w-4"
                        fill="none"
                        viewBox="0 0 24 24"
                        stroke="currentColor"
                        stroke-width="2.5">

                        <path
                            stroke-linecap="round"
                            stroke-linejoin="round"
                            d="M6 18L18 6M6 6l12 12" />

                    </svg>

                </button>

                <div
                    class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3"
                    data-rubric-criteria-list>

                    <?php foreach (($currentRubric['criteria'] ?? []) as $criterion): ?>

                        <div class="flex flex-col gap-2 rounded-xl bg-[#F2F2F2] p-4">

                            <span class="font-bold text-md text-brand">
                                <?= esc((int) $criterion['max_points']) ?> pts
                            </span>

                            <span class="font-bold text-sm text-[#646464]">
                                <?= esc($criterion['criteria_title']) ?>
                            </span>

                            <span class="text-sm text-[#646464]">
                                <?= esc($criterion['description']) ?>
                            </span>

                        </div>

                    <?php endforeach; ?>

                </div>

                <div class="flex items-center justify-end">

                    <button
                        type="button"
                        class="inline-flex shrink-0 items-center gap-1.5 rounded-lg px-3 py-1.5 text-sm font-semibold text-brand transition-colors
                            hover:bg-brand/10"
                        data-edit-rubric
                        data-rubric-id="<?= esc($currentRubric['id'] ?? '') ?>">

                        <svg
                            xmlns="http://www.w3.org/2000/svg"
                            class="h-4 w-4"
                            fill="none"
                            viewBox="0 0 24 24"
                            stroke="currentColor"
                            stroke-width="2">

                            <path
                                stroke-linecap="round"
                                stroke-linejoin="round"
                                d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5M16.5 3.5a2.121 2.121 0 113 3L12 14l-4 1 1-4 7.5-7.5z" />

                        </svg>

                        Edit

                    </button>

                </div>

            </div>

        </div>

    </div>

    <?= $this->include('pages/courses/features/assessments/partials/modals/rubric-selector') ?>
    <?= $this->include('pages/courses/features/assessments/partials/modals/rubric-editor') ?>
    <?= $this->include('pages/courses/features/assessments/partials/modals/rubric-create') ?>
    <?= $this->include('pages/courses/features/assessments/partials/modals/rubric-delete') ?>
    <?= $this->include('pages/courses/features/assessments/partials/modals/rubric-criteria-create') ?>
    <?= $this->include('pages/courses/features/assessments/partials/modals/rubric-criteria-editor') ?>
    <?= $this->include('pages/courses/features/assessments/partials/modals/rubric-criteria-delete') ?>

    <?php
        $submissionTypeLabels = array_keys(array_filter([
            'File upload' => (bool) ($currentSettings['allow_file_upload'] ?? false),
            'Text entry'  => (bool) ($currentSettings['allow_text_entry'] ?? false),
            'Link'        => (bool) ($currentSettings['allow_link_entry'] ?? false),
        ]));
    ?>

    <!-- Allowed submission types -->
    <div class="flex flex-col gap-3">

        <div class="flex items-center gap-3">
            <?= icon('work', 'h-5 w-5') ?>

            <span class="font-bold text-lg text-brand">
                Allowed submission types
            </span>
        </div>

        <div class="flex flex-col gap-3 rounded-2xl border border-gray-200 bg-white p-5">

            <div class="flex flex-wrap gap-2">

                <?php if ($submissionTypeLabels === []): ?>

                    <span class="text-sm text-[#767676]">
                        No submission method configured. Click "Configure" to allow file upload, text entry, or link submissions.
                    </span>

                <?php else: ?>

                    <?php foreach ($submissionTypeLabels as $label): ?>
                        <span class="rounded-lg bg-[#F2F2F4] px-3 py-1.5 text-sm font-semibold text-[#646464]">
                            <?= esc($label) ?>
                        </span>
                    <?php endforeach; ?>

                <?php endif; ?>

            </div>

            <div class="flex items-center justify-end">

                <button
                    type="button"
                    title="Configure submission types" aria-label="Configure submission types"
                    class="inline-flex shrink-0 items-center gap-1.5 rounded-lg px-3 py-1.5 text-sm font-semibold text-brand transition-colors
                        hover:bg-brand/10"
                    data-configure-submission-type>

                    <svg
                        xmlns="http://www.w3.org/2000/svg"
                        class="h-4 w-4"
                        fill="none"
                        viewBox="0 0 24 24"
                        stroke="currentColor"
                        stroke-width="2">

                        <path
                            stroke-linecap="round"
                            stroke-linejoin="round"
                            d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5M16.5 3.5a2.121 2.121 0 113 3L12 14l-4 1 1-4 7.5-7.5z" />

                    </svg>

                    Configure

                </button>

            </div>

        </div>

    </div>

    <?= $this->include('pages/courses/features/assessments/partials/modals/submission-type') ?>

</div>