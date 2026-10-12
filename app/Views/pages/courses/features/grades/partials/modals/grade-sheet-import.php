<!--
    Offline grade sheet import: upload → preview (per-row / per-cell flags) → apply.
    Same flow as the quiz builder's import-questions modal.
    Wired in resources/js/pages/courses/features/grades/import-export/grade-sheet.js.
-->
<div
    id="grade-sheet-import-modal"
    class="fixed inset-0 z-50 hidden items-center justify-center bg-black/50 backdrop-blur-sm">

    <div class="flex max-h-[85vh] w-full max-w-3xl flex-col rounded-2xl bg-white shadow-2xl">

        <div class="flex items-center justify-between border-b border-gray-200 px-8 py-6">

            <h2 class="text-xl font-bold text-brand">
                Import Grade Sheet
            </h2>

            <button
                type="button"
                class="rounded-lg p-2 text-gray-500 transition hover:bg-gray-100"
                data-close-grade-sheet-import-modal>

                <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                </svg>

            </button>

        </div>

        <!-- Step 1: upload -->
        <div data-grade-sheet-step="upload" class="flex flex-col gap-4 p-8">

            <p class="text-sm text-gray-500">
                Upload a grade sheet exported from this page (.xlsx or .csv). Only manual items such as Attendance
                or Participation are read from it — quiz and assignment scores always come from grading in Mentora.
                You'll see every change before anything is saved.
            </p>

            <input
                type="file"
                data-grade-sheet-file
                accept=".xlsx,.csv"
                class="rounded-lg border border-gray-300 px-3 py-2 text-sm outline-none transition focus:border-brand">

            <div data-grade-sheet-error class="hidden rounded-xl bg-red-50 px-4 py-3 text-sm text-red-600"></div>

            <button
                type="button"
                data-grade-sheet-preview
                class="self-end rounded-xl bg-brand px-6 py-3 font-semibold text-white transition hover:bg-brand-dark disabled:cursor-not-allowed disabled:opacity-60">
                Preview Changes
            </button>

        </div>

        <!-- Step 2: preview -->
        <div data-grade-sheet-step="preview" class="hidden flex-1 flex-col gap-4 overflow-y-auto p-8">

            <div class="grid grid-cols-2 gap-3 text-center">

                <div class="rounded-xl bg-emerald-50 p-3">
                    <span class="block text-2xl font-bold text-emerald-600" data-grade-sheet-valid-count>0</span>
                    <span class="text-xs font-semibold text-emerald-700">Scores to import</span>
                </div>

                <div class="rounded-xl bg-red-50 p-3">
                    <span class="block text-2xl font-bold text-red-600" data-grade-sheet-flagged-count>0</span>
                    <span class="text-xs font-semibold text-red-700">Rows with problems (skipped)</span>
                </div>

            </div>

            <div data-grade-sheet-notes class="hidden rounded-xl bg-gray-50 px-4 py-3 text-xs text-gray-500"></div>

            <div data-grade-sheet-rows class="flex flex-col gap-2"></div>

        </div>

        <div class="flex items-center justify-between border-t border-gray-200 px-8 py-6">

            <span data-grade-sheet-status class="text-xs text-[#767676]"></span>

            <div class="flex gap-3">

                <button
                    type="button"
                    class="rounded-xl border border-gray-300 px-6 py-3 font-medium transition hover:bg-gray-100"
                    data-close-grade-sheet-import-modal>
                    Cancel
                </button>

                <button
                    type="button"
                    data-grade-sheet-apply
                    class="hidden rounded-xl bg-brand px-6 py-3 font-semibold text-white transition hover:bg-brand-dark disabled:cursor-not-allowed disabled:opacity-60">
                    Import Scores
                </button>

            </div>

        </div>

    </div>

</div>
