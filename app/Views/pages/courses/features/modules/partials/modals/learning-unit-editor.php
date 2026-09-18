<div
    id="learning-unit-editor-modal"
    class="fixed inset-0 z-50 hidden items-center justify-center bg-black/50 backdrop-blur-sm">

    <div class="w-full max-w-2xl rounded-2xl bg-white shadow-2xl">

        <!-- Header -->
        <div class="flex items-center justify-between border-b border-gray-200 px-8 py-6">

            <div class="flex flex-col">

                <h2
                    class="text-2xl font-bold text-[#0040A6]"
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
                class="rounded-lg p-2 text-gray-500 transition hover:bg-gray-100"
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
            class="flex flex-col gap-6 p-8">

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

                <input
                    type="text"
                    name="title"
                    autocomplete="off"
                    placeholder="e.g. Introduction to Programming"
                    class="rounded-xl border border-gray-300 px-4 py-3 outline-none transition focus:border-[#0040A6]"
                    required>

            </div>

            <div class="flex flex-col gap-2">

                <label
                    class="text-sm font-semibold text-gray-700">

                    Description

                </label>

                <textarea
                    name="description"
                    autocomplete="off"
                    rows="5"
                    placeholder="Write a short description for this learning unit..."
                    class="resize-none rounded-xl border border-gray-300 px-4 py-3 outline-none transition focus:border-[#0040A6]"></textarea>

            </div>

        </form>

        <!-- Footer -->
        <div class="flex justify-end gap-3 border-t border-gray-200 px-8 py-6">

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
                class="rounded-xl bg-[#0040A6] px-6 py-3 font-semibold text-white transition hover:bg-[#002068]">

                Create Learning Unit

            </button>

        </div>

    </div>

</div>