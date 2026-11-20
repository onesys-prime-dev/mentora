<div
    id="learning-unit-editor-modal"
    class="fixed inset-0 z-50 hidden items-center justify-center bg-black/50 backdrop-blur-sm">

    <div class="w-full max-w-2xl rounded-2xl bg-white shadow-2xl flex flex-col max-h-full overflow-y-auto">

        <!-- Header -->
        <div class="flex items-center justify-between border-b border-gray-200 px-5 sm:px-8 py-5 sm:py-6 shrink-0">

            <div class="flex flex-col">

                <h2
                    class="text-2xl font-bold text-brand"
                    data-learning-unit-editor-title>

                    Add Learning Unit

                </h2>

                <p
                    class="mt-1 text-sm text-gray-500"
                    data-learning-unit-editor-subtitle>

                    Create a new learning unit for this module.

                </p>

            </div>

            <button
                type="button"
                aria-label="Close"
                class="flex shrink-0 items-center justify-center rounded-lg p-2 text-gray-500 transition hover:bg-gray-100 pointer-coarse:min-h-11 pointer-coarse:min-w-11"
                data-close-learning-unit-modal>

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
                        d="M6 18L18 6M6 6l12 12"/>

                </svg>

            </button>

        </div>

        <!-- Body -->
        <form
            id="learning-unit-editor-form"
            class="flex flex-col gap-6 p-5 sm:p-8 min-h-0 flex-1 overflow-y-auto">

            <?= csrf_field() ?>

            <input
                type="hidden"
                name="mode"
                value="create">

            <input
                type="hidden"
                name="learning_unit_id">

            <div class="flex flex-col gap-2">

                <label
                    class="text-sm font-semibold text-gray-700">

                    Learning Unit Title

                </label>

                <input aria-label="Learning Unit Title"
                    type="text"
                    name="title"
                    autocomplete="off"
                    placeholder="e.g. Introduction to Programming"
                    class="rounded-xl border border-gray-300 px-4 py-3 outline-none transition focus:border-brand text-base sm:text-sm"
                    required>

            </div>

            <div class="flex flex-col gap-2">

                <label
                    class="text-sm font-semibold text-gray-700">

                    Description

                </label>

                <textarea aria-label="Description"
                    name="description"
                    autocomplete="off"
                    rows="5"
                    placeholder="Write a short description for this learning unit..."
                    class="resize-none rounded-xl border border-gray-300 px-4 py-3 outline-none transition focus:border-brand text-base sm:text-sm"></textarea>

            </div>

            <?= view('pages/courses/features/modules/partials/modals/publish-fields', ['idPrefix' => 'unit'], ['saveData' => false]) ?>

        </form>

        <!-- Footer -->
        <div class="flex justify-end gap-3 border-t border-gray-200 px-5 sm:px-8 py-5 sm:py-6 flex-wrap shrink-0">

            <button
                type="button"
                class="rounded-xl border border-gray-300 px-6 py-3 font-medium transition hover:bg-gray-100"
                data-close-learning-unit-modal>

                Cancel

            </button>

            <button
                type="submit"
                form="learning-unit-editor-form"
                data-learning-unit-editor-submit
                class="rounded-xl bg-brand px-6 py-3 font-semibold text-white transition hover:bg-brand-dark">

                Create Learning Unit

            </button>

        </div>

    </div>

</div>