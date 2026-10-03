<div
    id="import-questions-modal"
    class="fixed inset-0 z-50 hidden items-center justify-center bg-black/50 backdrop-blur-sm">

    <div class="flex max-h-[85vh] w-full max-w-2xl flex-col rounded-2xl bg-white shadow-2xl">

        <div class="flex items-center justify-between border-b border-gray-200 px-8 py-6">

            <h2 class="text-xl font-bold text-[#0040A6]">
                Import Questions
            </h2>

            <button
                type="button"
                class="rounded-lg p-2 text-gray-500 transition hover:bg-gray-100"
                data-close-import-questions-modal>

                <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                </svg>

            </button>

        </div>

        <!-- Step 1: upload -->
        <div data-import-step="upload" class="flex flex-col gap-4 p-8">

            <p class="text-sm text-gray-500">
                Upload a .docx exported from this quiz builder to re-import edits, or upload any other document
                to generate new questions from it with AI (reviewed before anything is added).
            </p>

            <input
                type="file"
                data-import-file-input
                accept=".docx"
                class="rounded-lg border border-gray-300 px-3 py-2 text-sm outline-none transition focus:border-[#0040A6]">

            <div data-import-error class="hidden rounded-xl bg-red-50 px-4 py-3 text-sm text-red-600"></div>

            <button
                type="button"
                data-import-preview-button
                class="self-end rounded-xl bg-[#0040A6] px-6 py-3 font-semibold text-white transition hover:bg-[#002068] disabled:cursor-not-allowed disabled:opacity-60">
                Preview Changes
            </button>

        </div>

        <!-- Step 2: diff preview -->
        <div data-import-step="diff" class="hidden flex-1 flex-col gap-4 overflow-y-auto p-8">

            <div class="grid grid-cols-3 gap-3 text-center">

                <div class="rounded-xl bg-emerald-50 p-3">
                    <span class="block text-2xl font-bold text-emerald-600" data-import-added-count>0</span>
                    <span class="text-xs font-semibold text-emerald-700">Added</span>
                </div>

                <div class="rounded-xl bg-amber-50 p-3">
                    <span class="block text-2xl font-bold text-amber-600" data-import-changed-count>0</span>
                    <span class="text-xs font-semibold text-amber-700">Changed</span>
                </div>

                <div class="rounded-xl bg-red-50 p-3">
                    <span class="block text-2xl font-bold text-red-600" data-import-removed-count>0</span>
                    <span class="text-xs font-semibold text-red-700">Missing</span>
                </div>

            </div>

            <div data-import-diff-list class="flex flex-col gap-2"></div>

            <p class="text-xs text-[#767676]">
                Added and changed questions are always applied. For questions no longer found in the document,
                check the ones you want to actually delete — anything left unchecked stays in the quiz.
            </p>

        </div>

        <div class="flex items-center justify-between border-t border-gray-200 px-8 py-6">

            <span data-import-status class="text-xs text-[#767676]"></span>

            <div class="flex gap-3">

                <button
                    type="button"
                    class="rounded-xl border border-gray-300 px-6 py-3 font-medium transition hover:bg-gray-100"
                    data-close-import-questions-modal>
                    Cancel
                </button>

                <button
                    type="button"
                    data-import-apply-button
                    class="hidden rounded-xl bg-[#0040A6] px-6 py-3 font-semibold text-white transition hover:bg-[#002068]">
                    Apply Import
                </button>

            </div>

        </div>

    </div>

</div>
