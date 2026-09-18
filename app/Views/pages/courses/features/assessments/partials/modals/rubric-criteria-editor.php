<div
    id="rubric-criteria-editor-modal"
    class="fixed inset-0 z-50 hidden items-center justify-center bg-black/50 backdrop-blur-sm">

    <div class="w-full max-w-lg rounded-2xl bg-white shadow-2xl">

        <!-- Header -->
        <div class="flex items-center justify-between border-b border-gray-200 px-8 py-6">

            <h2 class="text-2xl font-bold text-[#0040A6]">
                Edit Criteria
            </h2>

            <button
                type="button"
                class="rounded-lg p-2 text-gray-500 transition hover:bg-gray-100"
                data-close-rubric-criteria-modal>

                <svg
                    xmlns="http://www.w3.org/2000/svg"
                    class="h-6 w-6"
                    fill="none"
                    viewBox="0 0 24 24"
                    stroke="currentColor">

                    <path
                        stroke-linecap="round"
                        stroke-linejoin="round"
                        stroke-width="2"
                        d="M6 18L18 6M6 6l12 12" />

                </svg>

            </button>

        </div>

        <!-- Body -->
        <form
            id="rubric-criteria-editor-form"
            class="flex flex-col gap-6 p-8">

            <div class="flex flex-col gap-2">

                <label class="text-sm font-semibold text-gray-700">
                    Criteria Title
                </label>

                <input
                    type="text"
                    name="title"
                    autocomplete="off"
                    placeholder="e.g. Logic & Correctness"
                    class="rounded-xl border border-gray-300 px-4 py-3 outline-none transition focus:border-[#0040A6]"
                    data-criteria-editor-title
                    required>

            </div>

            <div class="flex flex-col gap-2">

                <label class="text-sm font-semibold text-gray-700">
                    Description
                </label>

                <textarea
                    name="description"
                    autocomplete="off"
                    rows="3"
                    placeholder="What does this criterion evaluate?"
                    class="resize-none rounded-xl border border-gray-300 px-4 py-3 outline-none transition focus:border-[#0040A6]"
                    data-criteria-editor-description></textarea>

            </div>

            <div class="flex flex-col gap-2">

                <label class="text-sm font-semibold text-gray-700">
                    Max Points
                </label>

                <input
                    type="number"
                    name="max_points"
                    min="0"
                    step="1"
                    autocomplete="off"
                    placeholder="e.g. 50"
                    class="w-40 rounded-xl border border-gray-300 px-4 py-3 outline-none transition focus:border-[#0040A6]"
                    data-criteria-editor-max-points
                    required>

            </div>

        </form>

        <!-- Footer -->
        <div class="flex items-center justify-between gap-3 border-t border-gray-200 px-8 py-6">

            <button
                type="button"
                class="rounded-xl border border-red-200 px-5 py-2.5 font-medium text-red-600 transition hover:bg-red-50"
                data-delete-rubric-criteria>

                Delete

            </button>

            <div class="flex gap-3">

                <button
                    type="button"
                    class="rounded-xl border border-gray-300 px-6 py-3 font-medium transition hover:bg-gray-100"
                    data-close-rubric-criteria-modal>

                    Cancel

                </button>

                <button
                    type="submit"
                    form="rubric-criteria-editor-form"
                    class="rounded-xl bg-[#0040A6] px-6 py-3 font-semibold text-white transition hover:bg-[#002068]">

                    Save Changes

                </button>

            </div>

        </div>

    </div>

</div>
