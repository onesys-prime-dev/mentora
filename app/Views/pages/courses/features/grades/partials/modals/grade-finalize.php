<!--
    Finalize a section's grades, or reopen them. Opened from the Grades toolbar;
    filled by finalize/finalize-dialog.js from GradeFinalizationController::show.
    Finalizing counts work that was never graded or handed in as 0, freezes each
    student's final grade and locks the gradebook until it is reopened.
-->
<div
    id="grade-finalize-modal"
    class="fixed inset-0 z-50 hidden items-center justify-center bg-black/50 backdrop-blur-sm">

    <div class="w-full max-w-lg rounded-2xl bg-white shadow-2xl">

        <div class="flex items-center justify-between border-b border-gray-200 px-8 py-6">
            <h2 class="text-xl font-bold text-brand" data-finalize-title>Finalize grades</h2>
            <button type="button" class="rounded-lg p-2 text-gray-500 transition hover:bg-gray-100" aria-label="Close" data-close-grade-finalize-modal>
                <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                </svg>
            </button>
        </div>

        <div class="flex flex-col gap-5 p-8" data-finalize-body aria-live="polite">
            <p class="text-sm text-gray-500" data-finalize-loading>Checking the gradebook…</p>
        </div>

        <div class="flex justify-end gap-3 border-t border-gray-200 px-8 py-6">
            <button type="button" class="rounded-xl border border-gray-300 px-6 py-3 font-medium transition hover:bg-gray-100" data-close-grade-finalize-modal>
                Cancel
            </button>
            <button type="button" class="hidden rounded-xl bg-brand px-6 py-3 font-semibold text-white transition hover:bg-brand-dark disabled:opacity-50" data-finalize-confirm>
                Finalize grades
            </button>
        </div>

    </div>

</div>
