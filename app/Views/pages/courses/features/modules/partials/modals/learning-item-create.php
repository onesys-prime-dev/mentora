<div
    id="learning-item-create-modal"
    class="fixed inset-0 z-50 hidden items-center justify-center bg-black/50 backdrop-blur-sm">

    <div class="w-full max-w-2xl rounded-2xl bg-white shadow-2xl">

        <!-- Header -->
        <div class="flex items-center justify-between border-b border-gray-200 px-8 py-6">

            <div class="flex flex-col">

                <h2
                    class="text-2xl font-bold text-[#0040A6]"
                    data-learning-item-create-title>

                    Add Learning item

                </h2>

                <p
                    class="mt-1 text-sm text-gray-500"
                    data-learning-item-create-subtitle>

                    Create a new learning item for this learning unit.

                </p>

            </div>

            <button
                type="button"
                class="rounded-lg p-2 text-gray-500 transition hover:bg-gray-100"
                data-close-learning-item-modal>

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
            id="learning-item-create-form"
            class="flex flex-col gap-6 p-8">

            <?= csrf_field() ?>

            <input
                type="hidden"
                name="module_id">

            <input
                type="hidden"
                name="learning_unit_id">

            <div class="flex flex-col gap-2">

                <label
                    class="text-sm font-semibold text-gray-700">

                    Learning item Title

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

                    Learning item Title

                </label>

                <div class="grid grid-cols-3 gap-3">

                    <label class="cursor-pointer">
                        <input
                            type="radio"
                            name="item_type"
                            value="reading"
                            class="peer hidden"
                            checked>

                        <div class="rounded-xl border border-gray-300 px-4 py-3 text-center transition peer-checked:border-[#0040A6] peer-checked:bg-[#0040A6] peer-checked:text-white">
                            Reading
                        </div>
                    </label>

                    <label class="cursor-pointer">
                        <input
                            type="radio"
                            name="item_type"
                            value="video"
                            class="peer hidden">

                        <div class="rounded-xl border border-gray-300 px-4 py-3 text-center transition peer-checked:border-[#0040A6] peer-checked:bg-[#0040A6] peer-checked:text-white">
                            Video
                        </div>
                    </label>

                    <label class="cursor-pointer">
                        <input
                            type="radio"
                            name="item_type"
                            value="pdf"
                            class="peer hidden">

                        <div class="rounded-xl border border-gray-300 px-4 py-3 text-center transition peer-checked:border-[#0040A6] peer-checked:bg-[#0040A6] peer-checked:text-white">
                            PDF
                        </div>
                    </label>

                    <label class="cursor-pointer">
                        <input
                            type="radio"
                            name="item_type"
                            value="link"
                            class="peer hidden">

                        <div class="rounded-xl border border-gray-300 px-4 py-3 text-center transition peer-checked:border-[#0040A6] peer-checked:bg-[#0040A6] peer-checked:text-white">
                            Link
                        </div>
                    </label>

                    <label class="cursor-pointer">
                        <input
                            type="radio"
                            name="item_type"
                            value="assignment"
                            class="peer hidden">

                        <div class="rounded-xl border border-gray-300 px-4 py-3 text-center transition peer-checked:border-[#0040A6] peer-checked:bg-[#0040A6] peer-checked:text-white">
                            Assignment
                        </div>
                    </label>

                    <label class="cursor-pointer">
                        <input
                            type="radio"
                            name="item_type"
                            value="quiz"
                            class="peer hidden">

                        <div class="rounded-xl border border-gray-300 px-4 py-3 text-center transition peer-checked:border-[#0040A6] peer-checked:bg-[#0040A6] peer-checked:text-white">
                            Quiz
                        </div>
                    </label>

                    <label class="cursor-pointer">
                        <input
                            type="radio"
                            name="item_type"
                            value="discussion"
                            class="peer hidden">

                        <div class="rounded-xl border border-gray-300 px-4 py-3 text-center transition peer-checked:border-[#0040A6] peer-checked:bg-[#0040A6] peer-checked:text-white">
                            Discussion
                        </div>
                    </label>

                </div>

            </div>

        </form>

        <!-- Footer -->
        <div class="flex justify-end gap-3 border-t border-gray-200 px-8 py-6">

            <button
                type="button"
                class="rounded-xl border border-gray-300 px-6 py-3 font-medium transition hover:bg-gray-100"
                data-close-learning-item-modal>

                Cancel

            </button>

            <button
                type="submit"
                form="learning-item-create-form"
                data-learning-item-create-submit
                class="rounded-xl bg-[#0040A6] px-6 py-3 font-semibold text-white transition hover:bg-[#002068]">

                Create Learning item

            </button>

        </div>

    </div>

</div>