<?php // Below lg the action footer is pinned to the bottom edge: the page fills the window and loses its bottom padding, and a focused field scrolls clear of the footer. ?>
<div class="flex min-w-0 flex-1 flex-col p-5 sm:p-8 lg:p-10 max-lg:min-h-dvh max-lg:pb-0 max-lg:[&_:is(input,select,textarea,[contenteditable])]:scroll-mb-24">

    <div class="sticky-bar-top flex items-center justify-between gap-3 border-b border-gray-200 pb-4">

        <?php // The bar pins, so the trail inside it does not pin on its own; min-w-0 lets the title truncate. ?>
        <div class="min-w-0 flex-1">
            <?= view('pages/courses/features/assessments/partials/breadcrumb', [
                'currentAssessment' => $currentAssessment,
                'breadcrumbSticky' => false,
            ], ['saveData' => false]) ?>
        </div>

        <div class="flex shrink-0 items-center gap-2 sm:gap-4">

            <!-- Save Status -->
            <div
                data-editor-save-status
                class="flex items-center gap-2 text-sm text-gray-500">

                <div
                    data-save-indicator
                    class="h-2 w-2 rounded-full bg-emerald-500">
                </div>

                <span data-save-message class="max-md:sr-only">
                    All changes saved
                </span>

                <span class="text-gray-300 max-md:hidden" aria-hidden="true">
                    •
                </span>

                <span data-save-time class="max-md:hidden">
                    Saved just now
                </span>

            </div>

            <div class="flex items-center gap-2 rounded-full bg-[#F4F8FF] px-3 py-1.5 max-sm:hidden">

                <svg
                    xmlns="http://www.w3.org/2000/svg"
                    class="h-4 w-4 text-brand"
                    fill="none"
                    viewBox="0 0 24 24"
                    stroke="currentColor"
                    stroke-width="2">

                    <path
                        stroke-linecap="round"
                        stroke-linejoin="round"
                        d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5M16.5 3.5a2.121 2.121 0 113 3L12 14l-4 1 1-4 7.5-7.5z"/>

                </svg>

                <span class="text-xs font-semibold uppercase tracking-wide text-brand">
                    Editing
                </span>

            </div>

            <button
                type="button"
                data-side-panel-toggle
                aria-controls="editor-inspector-panel"
                aria-expanded="false"
                class="hidden md:inline-flex lg:hidden min-h-11 items-center gap-2 rounded-xl border border-gray-300 bg-white px-4 text-sm font-semibold text-brand transition hover:border-brand">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M10.5 6h9.75M10.5 6a1.5 1.5 0 11-3 0m3 0a1.5 1.5 0 10-3 0M3.75 6H7.5m3 12h9.75m-9.75 0a1.5 1.5 0 01-3 0m3 0a1.5 1.5 0 00-3 0m-3.75 0H7.5m9-6h3.75m-3.75 0a1.5 1.5 0 01-3 0m3 0a1.5 1.5 0 00-3 0m-9.75 0h9.75" />
                </svg>
                Settings
            </button>

        </div>

    </div>

    <div class="flex flex-1">

        <?php // Below md the settings stack under the content; at md–lg they are a side panel; from lg up they sit beside it. ?>
        <section class="flex min-w-0 flex-1 flex-col gap-8 py-6 lg:flex-row lg:gap-12">

            <div class="flex min-w-0 flex-1 flex-col gap-5">

                <!-- header -->
                <?= $this->include('pages/courses/features/assessments/partials/view-header') ?>

                <!-- contents -->
                <?= $this->include('pages/courses/features/assessments/assessment-types/editor/' . strtolower($currentAssessment['assessment_type'])) ?>

            </div>

            <?= $this->include('pages/courses/features/assessments/partials/editor-inspector-panel') ?>

        </section>

    </div>

    <?= $this->include('pages/courses/features/assessments/partials/editor-actions') ?>

    <?= $this->include('pages/courses/features/assessments/partials/modals/assessment-delete') ?>

    <?= $this->include('pages/courses/features/assessments/partials/modals/assignees') ?>

    <?php if (strtolower((string) ($currentAssessment['assessment_type'] ?? '')) === 'quiz') : ?>
        <?= $this->include('pages/courses/features/assessments/partials/modals/quiz-password') ?>
    <?php endif; ?>

</div>