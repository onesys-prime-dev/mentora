<!--
    Override a student's final grade. Clearing (leave percentage blank)
    restores the calculated grade. Every change is recorded (grade_history).
    Where the institution has deans the same dialog sends a request instead
    (title, note, status and buttons are set by roster/override-dialog.js).
-->
<div
    id="grade-override-modal"
    class="fixed inset-0 z-50 hidden items-center justify-center bg-black/50 backdrop-blur-sm">

    <div class="w-full max-w-md rounded-2xl bg-white shadow-2xl">

        <div class="flex items-center justify-between border-b border-gray-200 px-8 py-6">
            <h2 class="text-xl font-bold text-brand" data-override-title>Override Final Grade</h2>
            <button type="button" class="rounded-lg p-2 text-gray-500 transition hover:bg-gray-100" data-close-grade-override-modal>
                <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                </svg>
            </button>
        </div>

        <form id="grade-override-form" class="flex flex-col gap-6 p-8">

            <input type="hidden" data-override-student-id>

            <p class="text-sm text-gray-500">
                <span data-override-student-name class="font-semibold text-[#646464]"></span> —
                calculated grade: <span data-override-calculated-percentage class="font-semibold"></span>
            </p>

            <p class="hidden rounded-xl bg-brand-tint px-4 py-3 text-sm text-brand-dark" data-override-note></p>
            <p class="hidden rounded-xl bg-[#FFF1D6] px-4 py-3 text-sm font-medium text-[#6F4A00]" data-override-status role="status"></p>

            <div class="flex flex-col gap-2">
                <label class="text-sm font-semibold text-gray-700">Override percentage</label>
                <input type="number" min="0" max="100" step="0.01" data-override-percentage placeholder="Leave blank to clear the override"
                    class="rounded-xl border border-gray-300 px-4 py-3 outline-none transition focus:border-brand">
            </div>

            <div class="flex flex-col gap-2">
                <label class="text-sm font-semibold text-gray-700">Reason</label>
                <textarea data-override-reason rows="3" placeholder="e.g. Re-evaluation after student appeal"
                    class="rounded-xl border border-gray-300 px-4 py-3 text-sm outline-none transition focus:border-brand"></textarea>
            </div>

            <div data-override-error class="hidden rounded-xl bg-red-50 px-4 py-3 text-sm text-red-600"></div>

        </form>

        <div class="flex justify-end gap-3 border-t border-gray-200 px-8 py-6">
            <button type="button" class="rounded-xl border border-gray-300 px-6 py-3 font-medium transition hover:bg-gray-100" data-close-grade-override-modal>
                Cancel
            </button>
            <button type="button" class="hidden rounded-xl border border-[#C33939]/40 px-6 py-3 font-medium text-[#C33939] transition hover:bg-[#FDEDEC]" data-override-withdraw>
                Withdraw request
            </button>
            <button type="submit" form="grade-override-form" class="rounded-xl bg-brand px-6 py-3 font-semibold text-white transition hover:bg-brand-dark" data-override-submit>
                Save
            </button>
        </div>

    </div>

</div>
