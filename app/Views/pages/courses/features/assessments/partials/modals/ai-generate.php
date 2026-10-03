<?php

/*
| "Learning Unit" source = the unit this quiz sits under ($sourceUnit, from
| LearningUnitContentExtractor::unitForAssessment). No unit → no tab.
*/

$sourceUnit ??= null;

$sourceUnitReadable = $sourceUnit ? $sourceUnit['readings'] + $sourceUnit['pdfs'] : 0;

$sourceUnitSummary = $sourceUnit
    ? implode(' and ', array_filter([
        $sourceUnit['readings'] ? $sourceUnit['readings'] . ' ' . ($sourceUnit['readings'] === 1 ? 'reading' : 'readings') : null,
        $sourceUnit['pdfs'] ? $sourceUnit['pdfs'] . ' ' . ($sourceUnit['pdfs'] === 1 ? 'PDF' : 'PDFs') : null,
    ]))
    : '';
?>

<div
    id="ai-generate-modal"
    class="fixed inset-0 z-50 hidden items-center justify-center bg-black/50 backdrop-blur-sm">

    <div class="w-full max-w-lg rounded-2xl bg-white shadow-2xl">

        <div class="flex items-center justify-between border-b border-gray-200 px-8 py-6">

            <h2 class="text-xl font-bold text-[#0040A6]">
                Generate Questions with AI
            </h2>

            <button
                type="button"
                class="rounded-lg p-2 text-gray-500 transition hover:bg-gray-100"
                data-close-ai-generate-modal>

                <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                </svg>

            </button>

        </div>

        <form id="ai-generate-form" class="flex flex-col gap-5 p-8">

            <p class="rounded-xl bg-[#F2F7FF] px-4 py-3 text-xs text-[#0040A6]">
                Runs on a locally-hosted AI model — nothing you paste or upload here leaves this server.
                Questions land in a review tray first; nothing is added to the quiz until you accept it.
            </p>

            <!-- Source tabs -->
            <div class="flex flex-col gap-2">

                <label class="text-xs font-semibold uppercase tracking-wide text-gray-500">
                    Source
                </label>

                <div class="inline-flex w-fit rounded-xl bg-gray-100 p-1" data-ai-source-tabs>

                    <button type="button" data-ai-source-tab="topic" class="rounded-lg px-4 py-1.5 text-sm font-semibold transition">Topic</button>
                    <button type="button" data-ai-source-tab="text" class="rounded-lg px-4 py-1.5 text-sm font-semibold transition">Paste Text</button>
                    <button type="button" data-ai-source-tab="file" class="rounded-lg px-4 py-1.5 text-sm font-semibold transition">Upload File</button>
                    <?php if ($sourceUnit): ?>
                        <button type="button" data-ai-source-tab="learning_unit" class="rounded-lg px-4 py-1.5 text-sm font-semibold transition">Learning Unit</button>
                    <?php endif; ?>

                </div>

                <div data-ai-source-panel="topic" class="flex flex-col gap-1.5">
                    <input
                        type="text"
                        name="topic"
                        placeholder="e.g. Basic photosynthesis for a high school biology class"
                        class="rounded-lg border border-gray-300 px-3 py-2 text-sm outline-none transition focus:border-[#0040A6]">
                </div>

                <div data-ai-source-panel="text" class="hidden flex-col gap-1.5">
                    <textarea
                        name="source_text"
                        rows="5"
                        placeholder="Paste reference material here — the AI will write original questions inspired by it, never copy it verbatim."
                        class="rounded-lg border border-gray-300 px-3 py-2 text-sm outline-none transition focus:border-[#0040A6]"></textarea>
                </div>

                <div data-ai-source-panel="file" class="hidden flex-col gap-1.5">
                    <input
                        type="file"
                        name="file"
                        accept=".pdf,.png,.jpg,.jpeg,.webp"
                        class="rounded-lg border border-gray-300 px-3 py-2 text-sm outline-none transition focus:border-[#0040A6]">
                    <span class="text-xs text-[#767676]">PDF or image (scanned pages are read with OCR).</span>
                </div>

                <?php if ($sourceUnit): ?>

                    <!-- The quiz's own unit — fixed, not a choice. -->
                    <div
                        data-ai-source-panel="learning_unit"
                        data-ai-source-readable="<?= $sourceUnitReadable > 0 ? '1' : '0' ?>"
                        class="hidden flex-col gap-1.5">

                        <div class="flex items-center gap-3 rounded-xl border border-gray-200 bg-[#F9F9FC] px-4 py-3">

                            <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-white shadow-sm">
                                <?= icon('learning-unit', 'h-5 w-5') ?>
                            </span>

                            <div class="flex min-w-0 flex-col">
                                <span class="truncate text-[11px] font-semibold uppercase tracking-wide text-[#767676]"><?= esc($sourceUnit['module_title']) ?></span>
                                <span class="truncate text-sm font-bold text-[#0040A6]" data-ai-source-unit-title><?= esc($sourceUnit['title']) ?></span>
                                <?php if ($sourceUnitReadable > 0): ?>
                                    <span class="text-xs text-gray-500">Reads <?= esc($sourceUnitSummary) ?></span>
                                <?php endif; ?>
                            </div>

                        </div>

                        <?php if ($sourceUnitReadable > 0): ?>
                            <span class="text-xs text-[#767676]">Questions come from this quiz's learning unit. Videos, links and other assessments aren't read yet.</span>
                        <?php else: ?>
                            <span class="rounded-lg border border-[#FFE3A3] bg-[#FFF8E6] px-3 py-2 text-xs text-[#946200]">
                                This unit has no readings or PDFs yet — add some, or use another source.
                            </span>
                        <?php endif; ?>

                    </div>

                <?php endif; ?>

            </div>

            <!-- Count + points -->
            <div class="grid grid-cols-2 gap-4">

                <div class="flex flex-col gap-1.5">
                    <label class="text-xs font-semibold uppercase tracking-wide text-gray-500">
                        How many questions?
                    </label>
                    <input
                        type="number"
                        name="count"
                        min="1"
                        max="100"
                        value="10"
                        class="rounded-lg border border-gray-300 px-3 py-2 text-sm outline-none transition focus:border-[#0040A6]">
                </div>

                <div class="flex flex-col gap-1.5">
                    <label class="text-xs font-semibold uppercase tracking-wide text-gray-500">
                        Points each <span class="normal-case text-[#767676]">(optional)</span>
                    </label>
                    <input
                        type="number"
                        name="points_per_question"
                        min="0"
                        step="0.5"
                        placeholder="AI decides"
                        class="rounded-lg border border-gray-300 px-3 py-2 text-sm outline-none transition focus:border-[#0040A6]">
                </div>

            </div>

            <!-- Type mix -->
            <div class="flex flex-col gap-1.5">

                <label class="text-xs font-semibold uppercase tracking-wide text-gray-500">
                    Question types
                </label>

                <div class="flex flex-col gap-2 rounded-xl border border-gray-200 p-3">

                    <?php foreach ([
                        'multiple_choice' => 'Multiple Choice',
                        'short_answer' => 'Short Answer',
                        'essay' => 'Essay',
                    ] as $key => $label): ?>

                        <label class="flex items-center justify-between gap-3">

                            <span class="text-sm text-gray-700"><?= esc($label) ?></span>

                            <input
                                type="number"
                                min="0"
                                data-ai-type-mix-count
                                data-type-key="<?= esc($key) ?>"
                                value="<?= $key === 'multiple_choice' ? '10' : '0' ?>"
                                class="w-20 rounded-lg border border-gray-300 px-2 py-1 text-sm text-center outline-none transition focus:border-[#0040A6]">

                        </label>

                    <?php endforeach; ?>

                </div>

                <span data-ai-type-mix-status class="text-xs font-medium">
                    10 of 10 questions allocated
                </span>

            </div>

            <div data-ai-generate-error class="hidden rounded-xl bg-red-50 px-4 py-3 text-sm text-red-600"></div>

        </form>

        <div class="flex justify-end gap-3 border-t border-gray-200 px-8 py-6">

            <button
                type="button"
                class="rounded-xl border border-gray-300 px-6 py-3 font-medium transition hover:bg-gray-100"
                data-close-ai-generate-modal>

                Cancel

            </button>

            <button
                type="submit"
                form="ai-generate-form"
                data-ai-generate-submit
                class="inline-flex items-center justify-center gap-2 rounded-xl bg-[#0040A6] px-6 py-3 font-semibold text-white transition hover:bg-[#002068] disabled:cursor-not-allowed disabled:opacity-60">

                <span data-ai-generate-submit-icon class="hidden"></span>
                <span data-ai-generate-submit-label>Generate</span>

            </button>

        </div>

    </div>

</div>
