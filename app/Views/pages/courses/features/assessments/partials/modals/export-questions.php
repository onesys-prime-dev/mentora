<?php
/**
 * The Export modal of the quiz builder (opened by the export icon).
 *
 * Two choices: an editable file (the question builder's own templates, which import back in) or a printable
 * test. Each has a picker of only the formats the server can write (QuestionExportService). Blackboard, D2L,
 * Sakai and QTI packages, Moodle XML, TestGen (.tst) and HTML are import-only, so they are not offered. The print
 * options (versions, paper, shuffling, answer key only) sit under the printable test's picker.
 *
 * export-modal.js finds its parts by the data attributes below.
 *
 * @var array<string, mixed> $currentAssessment the quiz being built (the builder page's own variable)
 */

// [value sent to the export, label]
$editable = [
    ['docx', 'Word document (.docx)'],
    ['rtf', 'Rich Text (.rtf)'],
    ['xlsx', 'Excel (.xlsx)'],
    ['csv', 'CSV (.csv)'],
    ['gift', 'GIFT (Moodle, .txt)'],
    ['aiken', 'Aiken (.txt)'],
];

$printable = [
    ['test-pdf', 'PDF (.pdf)'],
    ['test-docx', 'Word document (.docx)'],
    ['test-rtf', 'Rich Text (.rtf)'],
];

$choice = 'flex cursor-pointer items-start gap-3 rounded-xl border border-gray-200 p-4 transition hover:bg-gray-50 has-[:checked]:border-brand has-[:checked]:bg-brand-tint';
$format = 'flex cursor-pointer items-center gap-2 rounded-lg px-2 py-1.5 text-sm text-gray-700 transition hover:bg-gray-50';
?>
<div
    id="export-questions-modal"
    role="dialog"
    aria-modal="true"
    aria-labelledby="export-questions-title"
    class="fixed inset-0 z-50 hidden items-center justify-center bg-black/50 backdrop-blur-sm">

    <div class="flex max-h-[88vh] w-full max-w-lg flex-col rounded-2xl bg-white shadow-2xl">

        <div class="flex items-center justify-between border-b border-gray-200 px-8 py-6">

            <h2 id="export-questions-title" class="text-xl font-bold text-brand">Export</h2>

            <button
                type="button"
                class="rounded-lg p-2 text-gray-500 transition hover:bg-gray-100"
                aria-label="Close"
                data-close-export-questions-modal>

                <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                </svg>

            </button>

        </div>

        <div class="flex min-h-0 flex-1 flex-col gap-5 overflow-y-auto p-8">

            <fieldset class="flex flex-col gap-3">

                <legend class="mb-1 text-xs font-semibold uppercase tracking-wide text-gray-500">What do you need?</legend>

                <label class="<?= $choice ?>">
                    <input type="radio" name="export-kind" value="editable" data-export-kind class="mt-0.5 h-4 w-4 accent-brand">
                    <span class="flex flex-col gap-0.5">
                        <span class="text-sm font-semibold text-gray-800">Editable file</span>
                        <span class="text-xs text-gray-500">Edit the questions and import the file back into this quiz.</span>
                    </span>
                </label>

                <label class="<?= $choice ?>">
                    <input type="radio" name="export-kind" value="printable" data-export-kind checked class="mt-0.5 h-4 w-4 accent-brand">
                    <span class="flex flex-col gap-0.5">
                        <span class="text-sm font-semibold text-gray-800">Printable test</span>
                        <span class="text-xs text-gray-500">The questions to hand out, with the answer key on the last pages.</span>
                    </span>
                </label>

            </fieldset>

            <!-- Editable file: the formats the question builder can write and read back. -->
            <fieldset data-export-formats="editable" class="hidden flex-col gap-2">

                <legend class="mb-1 text-xs font-semibold uppercase tracking-wide text-gray-500">Format</legend>

                <div class="flex flex-col gap-1">
                    <?php foreach ($editable as $i => [$value, $label]): ?>
                        <label class="<?= $format ?>">
                            <input type="radio" name="export-format-editable" value="<?= esc($value, 'attr') ?>" data-export-format-option <?= $i === 0 ? 'checked' : '' ?> class="h-4 w-4 accent-brand">
                            <?= esc($label) ?>
                        </label>
                    <?php endforeach; ?>
                </div>

                <p class="text-xs text-gray-500">
                    Word and Rich Text import back in with their formatting. Excel or CSV suit colleges; GIFT and Aiken suit
                    other quiz systems. Aiken holds single-answer multiple choice only.
                </p>

            </fieldset>

            <!-- Printable test: its formats, then the print options under them. -->
            <fieldset data-export-formats="printable" class="flex flex-col gap-3">

                <legend class="mb-1 text-xs font-semibold uppercase tracking-wide text-gray-500">Format</legend>

                <div class="flex flex-col gap-1">
                    <?php foreach ($printable as $i => [$value, $label]): ?>
                        <label class="<?= $format ?>">
                            <input type="radio" name="export-format-printable" value="<?= esc($value, 'attr') ?>" data-export-format-option <?= $i === 0 ? 'checked' : '' ?> class="h-4 w-4 accent-brand">
                            <?= esc($label) ?>
                        </label>
                    <?php endforeach; ?>
                </div>

                <div data-print-options class="flex flex-col gap-3 rounded-lg bg-gray-50 p-4 text-xs text-gray-700">

                    <div class="grid grid-cols-2 gap-3">

                        <label class="flex flex-col gap-1 font-medium">
                            <span>Versions</span>
                            <select data-print-versions class="rounded-md border border-gray-300 bg-white px-2 py-1.5 font-normal">
                                <option value="1">1 (A only)</option>
                                <option value="2">2 (A, B)</option>
                                <option value="3">3 (A to C)</option>
                                <option value="4">4 (A to D)</option>
                            </select>
                        </label>

                        <label class="flex flex-col gap-1 font-medium">
                            <span>Paper</span>
                            <select data-print-paper class="rounded-md border border-gray-300 bg-white px-2 py-1.5 font-normal">
                                <option value="A4">A4</option>
                                <option value="Letter">Letter</option>
                                <option value="Legal">Legal</option>
                            </select>
                        </label>

                    </div>

                    <label class="flex items-center gap-2">
                        <input type="checkbox" data-print-shuffle-questions class="h-4 w-4 accent-brand">
                        Shuffle the order of questions
                    </label>

                    <label class="flex items-center gap-2">
                        <input type="checkbox" data-print-shuffle-choices class="h-4 w-4 accent-brand">
                        Shuffle the choices
                    </label>

                    <label class="flex items-center gap-2">
                        <input type="checkbox" data-print-key-only class="h-4 w-4 accent-brand">
                        Answer key only
                    </label>

                    <p data-print-hint class="hidden text-[#767676]">
                        With several versions, A is the quiz as written and the others are shuffled. Reprinting gives the same papers.
                    </p>

                </div>

            </fieldset>

        </div>

        <div class="flex items-center justify-end gap-3 border-t border-gray-200 px-8 py-5">

            <button
                type="button"
                data-close-export-questions-modal
                class="rounded-xl border border-gray-300 px-4 py-2 text-sm font-semibold text-gray-700 transition hover:bg-gray-50">
                Cancel
            </button>

            <button
                type="button"
                data-export-questions
                data-assessment-id="<?= esc((string) $currentAssessment['id'], 'attr') ?>"
                class="rounded-xl bg-brand px-5 py-2 text-sm font-semibold text-white transition hover:bg-brand-dark">
                Export
            </button>

        </div>

    </div>

</div>
