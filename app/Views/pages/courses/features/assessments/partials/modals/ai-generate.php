<?php

/*
| The only source is the unit this quiz sits under ($sourceUnit, from
| LearningUnitContentExtractor::unitForAssessment): the person ticks which of
| its readings and PDFs to write questions from. No unit: nothing to read.
*/

$sourceUnit ??= null;

$unitItems = $sourceUnit['items'] ?? [];

// Background jobs send the chosen text to the hosted provider; the original
// in-request service runs a model on this server.
$aiIsExternal = \App\Services\Courses\Assessments\AiJobGeneration::enabled();
?>

<div
    id="ai-generate-modal"
    class="fixed inset-0 z-50 hidden items-center justify-center bg-black/50 backdrop-blur-sm">

    <div class="flex max-h-[90vh] w-full max-w-lg flex-col rounded-2xl bg-white shadow-2xl">

        <div class="flex shrink-0 items-center justify-between border-b border-gray-200 px-8 py-6">

            <h2 class="text-xl font-bold text-brand">
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

        <form id="ai-generate-form" class="flex min-h-0 flex-1 flex-col gap-5 overflow-y-auto p-8" data-ai-source-usable="<?= $unitItems !== [] ? '1' : '0' ?>">

            <p class="rounded-xl bg-brand-tint px-4 py-3 text-xs text-brand">
                <?php if ($aiIsExternal): ?>
                    The text of the readings and PDFs you choose is sent to an external AI service (Groq) to write the questions.
                    It is not used to identify anyone.
                <?php else: ?>
                    Runs on an AI model hosted on this server, so the text of the items you choose stays here.
                <?php endif; ?>
                Questions land in a review tray first; nothing is added to the quiz until you accept it.
            </p>

            <!-- Source: the quiz's own learning unit, and which of its readings and PDFs to use. -->
            <div class="flex flex-col gap-2" data-ai-source>

                <label class="text-xs font-semibold uppercase tracking-wide text-gray-500">
                    Write questions from
                </label>

                <?php if (! $sourceUnit): ?>

                    <span class="rounded-lg border border-[#FFE3A3] bg-[#FFF8E6] px-3 py-2 text-xs text-[#946200]">
                        This quiz isn't in a learning unit. Place it in a learning unit to generate questions from its readings and PDFs.
                    </span>

                <?php else: ?>

                    <div class="flex items-center gap-3 rounded-xl border border-gray-200 bg-[#F9F9FC] px-4 py-3">

                        <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-white shadow-sm">
                            <?= icon('learning-unit', 'h-5 w-5') ?>
                        </span>

                        <div class="flex min-w-0 flex-col">
                            <span class="truncate text-[11px] font-semibold uppercase tracking-wide text-[#767676]"><?= esc($sourceUnit['module_title']) ?></span>
                            <span class="truncate text-sm font-bold text-brand" data-ai-source-unit-title><?= esc($sourceUnit['title']) ?></span>
                        </div>

                    </div>

                    <?php if ($unitItems === []): ?>

                        <span class="rounded-lg border border-[#FFE3A3] bg-[#FFF8E6] px-3 py-2 text-xs text-[#946200]">
                            This unit has no readings or PDFs yet. Add some to generate questions from them.
                        </span>

                    <?php else: ?>

                        <div class="flex items-center justify-between gap-3">

                            <span class="text-xs text-gray-600" data-ai-selected-summary aria-live="polite">
                                <?= count($unitItems) ?> of <?= count($unitItems) ?> selected
                            </span>

                            <span class="flex items-center gap-3 text-xs font-semibold text-brand">
                                <button type="button" data-ai-select-all class="hover:underline">Select all</button>
                                <button type="button" data-ai-select-none class="hover:underline">Select none</button>
                            </span>

                        </div>

                        <ul class="flex max-h-56 flex-col gap-1.5 overflow-y-auto rounded-xl border border-gray-200 p-2" data-ai-items>

                            <?php foreach ($unitItems as $unitItem): ?>

                                <li>
                                    <label class="flex cursor-pointer items-center gap-3 rounded-lg px-2 py-2 transition hover:bg-[#F2F7FF]">

                                        <input
                                            type="checkbox"
                                            checked
                                            data-ai-item
                                            value="<?= (int) $unitItem['id'] ?>"
                                            class="h-4 w-4 shrink-0 rounded border-gray-300 accent-[#0040A6]">

                                        <span class="shrink-0 rounded-md bg-gray-100 px-2 py-0.5 text-[11px] font-semibold uppercase tracking-wide text-gray-600">
                                            <?= $unitItem['type'] === 'pdf' ? 'PDF' : 'Reading' ?>
                                        </span>

                                        <span class="min-w-0 flex-1 truncate text-sm text-gray-800"><?= esc($unitItem['title']) ?></span>

                                        <?php if ($unitItem['status'] !== 'published'): ?>
                                            <span class="shrink-0 rounded-full bg-[#FFF1D6] px-2 py-0.5 text-[11px] font-semibold text-[#946200]">Draft</span>
                                        <?php endif; ?>

                                    </label>
                                </li>

                            <?php endforeach; ?>

                        </ul>

                        <span class="text-xs text-[#767676]">Only the items ticked here are read. Videos, links and other assessments aren't used.</span>

                    <?php endif; ?>

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
                        class="rounded-lg border border-gray-300 px-3 py-2 text-sm outline-none transition focus:border-brand">
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
                        class="rounded-lg border border-gray-300 px-3 py-2 text-sm outline-none transition focus:border-brand">
                </div>

            </div>

            <!-- Type mix -->
            <div class="flex flex-col gap-1.5">

                <label class="text-xs font-semibold uppercase tracking-wide text-gray-500">
                    Question types
                </label>

                <div class="flex flex-col gap-2 rounded-xl border border-gray-200 p-3">

                    <?php
                    // The same four types, names and descriptions as the quiz builder's palette.
                    // Multiple Choice also covers "several right answers" and True/False, which the
                    // builder offers as settings of a multiple-choice question.
                    foreach ([
                        ['multiple_choice', 'Multiple Choice', 'Students pick one correct answer.', false],
                        ['multiple_answer', 'Several correct answers', 'A multiple-choice question where two or more choices are right.', true],
                        ['true_false', 'True / False', 'A multiple-choice statement with the choices True and False.', true],
                        ['short_answer', 'Short Answer', 'Students type a brief response.', false],
                        ['essay', 'Essay', 'Students write a longer response.', false],
                        ['file_upload', 'File Upload', 'Students upload a file as their answer.', false],
                    ] as [$key, $label, $hint, $isVariant]): ?>

                        <label class="flex items-center justify-between gap-3 <?= $isVariant ? 'pl-5' : '' ?>">

                            <span class="flex flex-col">
                                <span class="text-sm <?= $isVariant ? 'text-gray-600' : 'font-medium text-gray-800' ?>"><?= $isVariant ? '↳ ' : '' ?><?= esc($label) ?></span>
                                <span class="text-xs text-[#767676]"><?= esc($hint) ?></span>
                            </span>

                            <input
                                type="number"
                                min="0"
                                data-ai-type-mix-count
                                data-type-key="<?= esc($key) ?>"
                                value="<?= $key === 'multiple_choice' ? '10' : '0' ?>"
                                class="w-20 rounded-lg border border-gray-300 px-2 py-1 text-sm text-center outline-none transition focus:border-brand">

                        </label>

                    <?php endforeach; ?>

                </div>

                <span data-ai-type-mix-status class="text-xs font-medium">
                    10 of 10 questions allocated
                </span>

            </div>

            <!-- Fine-tune -->
            <details data-ai-fine-tune class="rounded-xl border border-gray-200 p-3">

                <summary class="cursor-pointer text-xs font-semibold uppercase tracking-wide text-gray-500">
                    Fine-tune the questions <span data-ai-fine-tune-summary class="ml-1 normal-case text-[#767676]"></span>
                </summary>

                <div class="mt-3 grid grid-cols-2 gap-3">

                    <label class="flex flex-col gap-1 text-xs font-medium text-gray-600">
                        Difficulty
                        <select name="ai_difficulty" class="rounded-lg border border-gray-300 bg-white px-2 py-2 text-sm font-normal">
                            <option value="mixed">Mixed</option>
                            <option value="easier">Easier</option>
                            <option value="harder">Harder</option>
                        </select>
                    </label>

                    <label class="flex flex-col gap-1 text-xs font-medium text-gray-600">
                        Kind of thinking
                        <select name="ai_level" class="rounded-lg border border-gray-300 bg-white px-2 py-2 text-sm font-normal">
                            <option value="any">Any</option>
                            <option value="recall">Remember facts</option>
                            <option value="understand">Understand ideas</option>
                            <option value="apply">Apply to a new case</option>
                        </select>
                    </label>

                    <label class="flex flex-col gap-1 text-xs font-medium text-gray-600">
                        Choices per question
                        <select name="ai_choices" class="rounded-lg border border-gray-300 bg-white px-2 py-2 text-sm font-normal">
                            <option value="3">3</option>
                            <option value="4" selected>4</option>
                            <option value="5">5</option>
                        </select>
                    </label>

                    <label class="flex flex-col gap-1 text-xs font-medium text-gray-600">
                        Language
                        <input
                            type="text"
                            name="ai_language"
                            maxlength="40"
                            placeholder="Same as the material"
                            class="rounded-lg border border-gray-300 px-2 py-2 text-sm font-normal outline-none focus:border-brand">
                    </label>

                    <label class="col-span-2 flex flex-col gap-1 text-xs font-medium text-gray-600">
                        Focus <span class="font-normal text-[#767676]">(optional)</span>
                        <input
                            type="text"
                            name="ai_focus"
                            maxlength="200"
                            placeholder="For example: chapter 3 formulas only"
                            class="rounded-lg border border-gray-300 px-2 py-2 text-sm font-normal outline-none focus:border-brand">
                    </label>

                </div>

                <button type="button" data-ai-fine-tune-reset class="mt-3 text-xs font-semibold text-brand hover:underline">
                    Reset to defaults
                </button>

            </details>

            <div data-ai-generate-error class="hidden rounded-xl bg-red-50 px-4 py-3 text-sm text-red-600"></div>

        </form>

        <div class="flex shrink-0 justify-end gap-3 border-t border-gray-200 px-8 py-6">

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
                class="inline-flex items-center justify-center gap-2 rounded-xl bg-brand px-6 py-3 font-semibold text-white transition hover:bg-brand-dark disabled:cursor-not-allowed disabled:opacity-60">

                <span data-ai-generate-submit-icon class="hidden"></span>
                <span data-ai-generate-submit-label>Generate</span>

            </button>

        </div>

    </div>

</div>
