<!--
    Override a student's final grade. Clearing (leave percentage blank)
    restores the calculated grade. Every change is recorded (grade_history).
-->
<div
    id="grade-override-modal"
    class="fixed inset-0 z-50 hidden items-center justify-center bg-black/50 backdrop-blur-sm">

    <div class="w-full max-w-md rounded-2xl bg-white shadow-2xl">

        <div class="flex items-center justify-between border-b border-gray-200 px-8 py-6">
            <h2 class="text-xl font-bold text-[#0040A6]">Override Final Grade</h2>
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

            <div class="flex flex-col gap-2">
                <label class="text-sm font-semibold text-gray-700">Override percentage</label>
                <input type="number" min="0" max="100" step="0.01" data-override-percentage placeholder="Leave blank to clear the override"
                    class="rounded-xl border border-gray-300 px-4 py-3 outline-none transition focus:border-[#0040A6]">
            </div>

            <div class="flex flex-col gap-2">
                <label class="text-sm font-semibold text-gray-700">Reason</label>
                <textarea data-override-reason rows="3" placeholder="e.g. Re-evaluation after student appeal"
                    class="rounded-xl border border-gray-300 px-4 py-3 text-sm outline-none transition focus:border-[#0040A6]"></textarea>
            </div>

            <div data-override-error class="hidden rounded-xl bg-red-50 px-4 py-3 text-sm text-red-600"></div>

        </form>

        <div class="flex justify-end gap-3 border-t border-gray-200 px-8 py-6">
            <button type="button" class="rounded-xl border border-gray-300 px-6 py-3 font-medium transition hover:bg-gray-100" data-close-grade-override-modal>
                Cancel
            </button>
            <button type="submit" form="grade-override-form" class="rounded-xl bg-[#0040A6] px-6 py-3 font-semibold text-white transition hover:bg-[#002068]">
                Save
            </button>
        </div>

    </div>

</div>
