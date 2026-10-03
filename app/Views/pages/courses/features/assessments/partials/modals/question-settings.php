<div
    id="question-settings-modal"
    class="fixed inset-0 z-50 hidden items-center justify-center bg-black/50 backdrop-blur-sm">

    <div class="w-full max-w-md rounded-2xl bg-white shadow-2xl">

        <!-- Header -->
        <div class="flex items-center justify-between border-b border-gray-200 px-8 py-6">

            <h2 class="text-xl font-bold text-[#0040A6]">
                Question Settings
            </h2>

            <button
                type="button"
                class="rounded-lg p-2 text-gray-500 transition hover:bg-gray-100"
                data-close-question-settings-modal>

                <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                </svg>

            </button>

        </div>

        <!-- Body -->
        <form
            id="question-settings-form"
            class="flex flex-col gap-6 p-8">

            <input type="hidden" data-question-settings-id>

            <div class="flex flex-col gap-2">

                <label class="text-sm font-semibold text-gray-700">
                    Points
                </label>

                <input
                    type="number"
                    min="0"
                    step="1"
                    class="w-32 rounded-xl border border-gray-300 px-4 py-3 outline-none transition focus:border-[#0040A6]"
                    data-question-settings-points
                    required>

            </div>

            <div class="flex items-center justify-between">

                <label class="text-sm font-medium text-gray-700">
                    Required Question
                </label>

                <label class="relative inline-flex shrink-0 cursor-pointer items-center">

                    <input
                        type="checkbox"
                        class="peer sr-only"
                        data-question-settings-required>

                    <div class="h-6 w-11 rounded-full bg-gray-300 transition-colors peer-checked:bg-[#0040A6]"></div>

                    <div class="absolute left-1 top-1 h-4 w-4 rounded-full bg-white transition-transform peer-checked:translate-x-5"></div>

                </label>

            </div>

            <div class="hidden flex-col gap-4 border-t border-gray-100 pt-4" data-question-settings-multiple-section>

                <div class="flex items-center justify-between">

                    <label class="text-sm font-medium text-gray-700">
                        Allow Multiple Answers
                    </label>

                    <label class="relative inline-flex shrink-0 cursor-pointer items-center">

                        <input
                            type="checkbox"
                            class="peer sr-only"
                            data-question-settings-allow-multiple>

                        <div class="h-6 w-11 rounded-full bg-gray-300 transition-colors peer-checked:bg-[#0040A6]"></div>

                        <div class="absolute left-1 top-1 h-4 w-4 rounded-full bg-white transition-transform peer-checked:translate-x-5"></div>

                    </label>

                </div>

                <div class="hidden flex-col gap-2" data-question-settings-answers-count-wrapper>

                    <label class="text-sm font-semibold text-gray-700">
                        How many answers?
                    </label>

                    <input
                        type="number"
                        min="2"
                        step="1"
                        class="w-32 rounded-xl border border-gray-300 px-4 py-3 outline-none transition focus:border-[#0040A6]"
                        data-question-settings-answers-count>

                    <span class="text-xs text-[#767676]">
                        How many choices students must select for this question.
                    </span>

                </div>

            </div>

            <!-- Short Answer only -->
            <div class="hidden flex-col gap-2 border-t border-gray-100 pt-4" data-question-settings-short-answer-section>

                <div class="flex items-center justify-between">

                    <label class="text-sm font-medium text-gray-700">
                        Case Sensitive
                    </label>

                    <label class="relative inline-flex shrink-0 cursor-pointer items-center">

                        <input
                            type="checkbox"
                            class="peer sr-only"
                            data-question-settings-case-sensitive>

                        <div class="h-6 w-11 rounded-full bg-gray-300 transition-colors peer-checked:bg-[#0040A6]"></div>

                        <div class="absolute left-1 top-1 h-4 w-4 rounded-full bg-white transition-transform peer-checked:translate-x-5"></div>

                    </label>

                </div>

                <span class="text-xs text-[#767676]">
                    Off: “cpu” matches “CPU”. On: the student’s answer must match the letter case of an accepted answer.
                </span>

            </div>

        </form>

        <!-- Footer -->
        <div class="flex justify-end gap-3 border-t border-gray-200 px-8 py-6">

            <button
                type="button"
                class="rounded-xl border border-gray-300 px-6 py-3 font-medium transition hover:bg-gray-100"
                data-close-question-settings-modal>

                Cancel

            </button>

            <button
                type="submit"
                form="question-settings-form"
                class="rounded-xl bg-[#0040A6] px-6 py-3 font-semibold text-white transition hover:bg-[#002068]">

                Save

            </button>

        </div>

    </div>

</div>
