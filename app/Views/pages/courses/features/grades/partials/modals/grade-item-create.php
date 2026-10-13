<!--
    Add a manual grade item (Attendance, Participation…) to a category.
    Same structure as the quiz builder's question-settings modal.
-->
<div
    id="grade-item-create-modal"
    class="fixed inset-0 z-50 hidden items-center justify-center bg-black/50 backdrop-blur-sm">

    <div class="w-full max-w-md rounded-2xl bg-white shadow-2xl">

        <div class="flex items-center justify-between border-b border-gray-200 px-8 py-6">

            <h2 class="text-xl font-bold text-brand">
                Add Manual Item
            </h2>

            <button type="button" class="rounded-lg p-2 text-gray-500 transition hover:bg-gray-100" data-close-grade-item-modal>
                <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                </svg>
            </button>

        </div>

        <form id="grade-item-create-form" class="flex flex-col gap-6 p-8">

            <input type="hidden" data-grade-item-create-category>

            <div class="flex flex-col gap-2">
                <label class="text-sm font-semibold text-gray-700">Name</label>
                <input type="text" data-grade-item-create-name required placeholder="e.g. Attendance"
                    class="rounded-xl border border-gray-300 px-4 py-3 outline-none transition focus:border-brand">
            </div>

            <div class="flex flex-col gap-2">
                <label class="text-sm font-semibold text-gray-700">Max points</label>
                <input type="number" min="0.01" step="0.5" data-grade-item-create-max required value="100"
                    class="w-32 rounded-xl border border-gray-300 px-4 py-3 outline-none transition focus:border-brand">
                <span class="text-xs text-[#767676]">Scores are entered through the offline grade sheet.</span>
            </div>

            <div data-grade-item-create-error class="hidden rounded-xl bg-red-50 px-4 py-3 text-sm text-red-600"></div>

        </form>

        <div class="flex justify-end gap-3 border-t border-gray-200 px-8 py-6">

            <button type="button" class="rounded-xl border border-gray-300 px-6 py-3 font-medium transition hover:bg-gray-100" data-close-grade-item-modal>
                Cancel
            </button>

            <button type="submit" form="grade-item-create-form"
                class="rounded-xl bg-brand px-6 py-3 font-semibold text-white transition hover:bg-brand-dark">
                Add Item
            </button>

        </div>

    </div>

</div>
