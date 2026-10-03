<div
    id="rubric-editor-modal"
    class="fixed inset-0 z-50 hidden items-center justify-center bg-black/50 backdrop-blur-sm">

    <div class="flex max-h-[85vh] w-full max-w-2xl flex-col rounded-2xl bg-white shadow-2xl">

        <!-- Header -->
        <div class="flex items-center justify-between border-b border-gray-200 px-8 py-6">

            <div class="flex flex-col">

                <h2 class="text-2xl font-bold text-[#0040A6]">
                    Edit Rubric
                </h2>

                <p class="mt-1 text-sm text-gray-500">
                    Update the rubric title, description, and criteria.
                </p>

            </div>

            <button
                type="button"
                class="rounded-lg p-2 text-gray-500 transition hover:bg-gray-100"
                data-close-rubric-modal>

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
            id="rubric-editor-form"
            class="flex flex-1 flex-col gap-6 overflow-y-auto p-8">

            <input
                type="hidden"
                name="rubric_id"
                data-rubric-editor-id>

            <div class="flex flex-col gap-2">

                <label class="text-sm font-semibold text-gray-700">
                    Rubric Title
                </label>

                <input
                    type="text"
                    name="title"
                    autocomplete="off"
                    placeholder="e.g. Programming Assignment Rubric"
                    class="rounded-xl border border-gray-300 px-4 py-3 outline-none transition focus:border-[#0040A6]"
                    data-rubric-editor-title
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
                    placeholder="Briefly describe what this rubric evaluates..."
                    class="resize-none rounded-xl border border-gray-300 px-4 py-3 outline-none transition focus:border-[#0040A6]"
                    data-rubric-editor-description></textarea>

            </div>

            <div class="flex flex-col gap-3">

                <div class="flex items-center justify-between">

                    <label class="text-sm font-semibold text-gray-700">
                        Rubric Criteria
                    </label>

                    <button
                        type="button"
                        class="inline-flex items-center gap-1.5 rounded-lg px-3 py-1.5 text-sm font-semibold text-[#0040A6] transition hover:bg-[#0040A6]/10"
                        data-add-rubric-criteria
                        data-context="editor">

                        <svg
                            xmlns="http://www.w3.org/2000/svg"
                            class="h-4 w-4"
                            fill="none"
                            viewBox="0 0 24 24"
                            stroke="currentColor"
                            stroke-width="2">

                            <path
                                stroke-linecap="round"
                                stroke-linejoin="round"
                                d="M12 5v14M5 12h14" />

                        </svg>

                        Add Criteria

                    </button>

                </div>

                <div
                    class="grid grid-cols-1 gap-3 sm:grid-cols-2"
                    data-rubric-criteria-editor-list>

                    <!-- populated via JS -->

                </div>

                <div
                    class="hidden text-center text-sm text-[#767676]"
                    data-rubric-criteria-editor-empty>

                    No criteria yet. Add one to get started.

                </div>

            </div>

        </form>

        <!-- Footer -->
        <div class="flex items-center justify-between gap-3 border-t border-gray-200 px-8 py-6">

            <button
                type="button"
                class="rounded-xl border border-red-200 px-5 py-2.5 font-medium text-red-600 transition hover:bg-red-50"
                data-delete-rubric>

                Delete Rubric

            </button>

            <div class="flex gap-3">

                <button
                    type="button"
                    class="rounded-xl border border-gray-300 px-6 py-3 font-medium transition hover:bg-gray-100"
                    data-close-rubric-modal>

                    Cancel

                </button>

                <button
                    type="submit"
                    form="rubric-editor-form"
                    class="rounded-xl bg-[#0040A6] px-6 py-3 font-semibold text-white transition hover:bg-[#002068]">

                    Save Changes

                </button>

            </div>

        </div>

    </div>

</div>
