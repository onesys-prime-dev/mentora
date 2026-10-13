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
        $allowFileUpload = (bool) ($currentSettings['allow_file_upload'] ?? false);
        $allowTextEntry = (bool) ($currentSettings['allow_text_entry'] ?? false);
        $allowLinkEntry = (bool) ($currentSettings['allow_link_entry'] ?? false);
        $hasSubmissionType = $allowFileUpload || $allowTextEntry || $allowLinkEntry;
        $firstTabRendered = false;
    ?>

    <!-- Your Work -->
    <div class="flex flex-col gap-3">

        <div class="flex items-center justify-between">

            <div class="flex items-center gap-3">
                <?= icon('work', 'h-5 w-5') ?>

                <span class="font-bold text-lg text-brand">
                    Your Work
                </span>
            </div>

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
                        d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z" />

                    <path
                        stroke-linecap="round"
                        stroke-linejoin="round"
                        d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />

                </svg>

                Configure

            </button>

        </div>

        <div
            class="group/work-tabs flex flex-col"
            data-submission-type-container>

            <?php if (!$hasSubmissionType): ?>

                <div class="flex flex-col items-center justify-center gap-2 rounded-2xl border border-dashed border-[#DBDBDB] bg-[#F9F9FC] p-10 text-center">

                    <span class="text-sm font-semibold text-[#646464]">
                        No submission method configured
                    </span>

                    <span class="text-xs text-[#767676]">
                        Click "Configure" above to allow file upload, text entry, or link submissions.
                    </span>

                </div>

            <?php else: ?>

                <div class="flex w-fit border-x border-t border-[#DBDBDB] rounded-t-xl overflow-hidden">

                    <?php if ($allowFileUpload): ?>

                        <label
                            class="cursor-pointer border-r border-[#DBDBDB] px-4 py-2 text-sm font-semibold text-[#8A8A8A] bg-[#F2F2F4] transition-colors
                                hover:text-brand
                                group-has-[#work-tab-upload:checked]/work-tabs:bg-white
                                group-has-[#work-tab-upload:checked]/work-tabs:text-brand">

                            <input
                                type="radio"
                                name="work-tab"
                                id="work-tab-upload"
                                class="hidden"
                                <?= !$firstTabRendered ? 'checked' : '' ?>>

                            File Upload
                        </label>

                        <?php $firstTabRendered = true; ?>

                    <?php endif; ?>

                    <?php if ($allowTextEntry): ?>

                        <label
                            class="cursor-pointer border-r border-[#DBDBDB] px-4 py-2 text-sm font-semibold text-[#8A8A8A] bg-[#F2F2F4] transition-colors
                                hover:text-brand
                                group-has-[#work-tab-text:checked]/work-tabs:bg-white
                                group-has-[#work-tab-text:checked]/work-tabs:text-brand">

                            <input
                                type="radio"
                                name="work-tab"
                                id="work-tab-text"
                                class="hidden"
                                <?= !$firstTabRendered ? 'checked' : '' ?>>

                            Text Entry
                        </label>

                        <?php $firstTabRendered = true; ?>

                    <?php endif; ?>

                    <?php if ($allowLinkEntry): ?>

                        <label
                            class="cursor-pointer px-4 py-2 text-sm font-semibold text-[#8A8A8A] bg-[#F2F2F4] transition-colors
                                hover:text-brand
                                group-has-[#work-tab-link:checked]/work-tabs:bg-white
                                group-has-[#work-tab-link:checked]/work-tabs:text-brand">

                            <input
                                type="radio"
                                name="work-tab"
                                id="work-tab-link"
                                class="hidden"
                                <?= !$firstTabRendered ? 'checked' : '' ?>>

                            Link
                        </label>

                        <?php $firstTabRendered = true; ?>

                    <?php endif; ?>

                </div>

                <?php if ($allowFileUpload): ?>

                    <div
                        class="hidden w-full min-h-100 p-10 flex-col justify-center items-center bg-white border border-[#DBDBDB] rounded-b-2xl rounded-r-2xl gap-2
                            group-has-[#work-tab-upload:checked]/work-tabs:flex">

                        <?= icon('work', 'h-10 w-10 opacity-40') ?>

                        <span class="font-semibold text-sm text-[#646464]">
                            Drag and drop your file here
                        </span>

                        <span class="text-xs text-[#767676]">
                            or click to browse
                        </span>

                    </div>

                <?php endif; ?>

                <?php if ($allowTextEntry): ?>

                    <div
                        class="hidden w-full min-h-100 p-10 flex-col justify-center items-center bg-white border border-[#DBDBDB] rounded-b-2xl rounded-r-2xl gap-2
                            group-has-[#work-tab-text:checked]/work-tabs:flex">

                        <textarea
                            class="h-full w-full resize-none border-0 text-sm text-[#646464] outline-none"
                            placeholder="Type your response here..."></textarea>

                    </div>

                <?php endif; ?>

                <?php if ($allowLinkEntry): ?>

                    <div
                        class="hidden w-full min-h-100 p-10 flex-col justify-center items-center bg-white border border-[#DBDBDB] rounded-b-2xl rounded-r-2xl gap-2
                            group-has-[#work-tab-link:checked]/work-tabs:flex">

                        <?= icon('work', 'h-10 w-10 opacity-40') ?>

                        <input
                            type="url"
                            placeholder="https://..."
                            class="w-full max-w-md rounded-xl border border-[#DBDBDB] px-4 py-3 text-center text-sm text-[#646464] outline-none transition focus:border-brand">

                        <span class="text-xs text-[#767676]">
                            Paste a link to your work (e.g. Google Docs, GitHub).
                        </span>

                    </div>

                <?php endif; ?>

            <?php endif; ?>

        </div>

    </div>

    <?= $this->include('pages/courses/features/assessments/partials/modals/submission-type') ?>

</div>