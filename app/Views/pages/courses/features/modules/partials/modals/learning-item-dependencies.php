<div
    id="learning-item-dependencies-modal"
    class="fixed inset-0 z-50 hidden items-center justify-center bg-black/50 backdrop-blur-sm">

    <div class="w-full max-w-xl rounded-2xl bg-white shadow-2xl">

        <!-- Header -->
        <div class="flex items-center justify-between border-b border-gray-200 px-8 py-6">

            <div class="flex flex-col">

                <h2 class="text-2xl font-bold text-[#0040A6]">
                    Dependencies
                </h2>

                <p class="mt-1 text-sm text-gray-500">
                    What students must complete before
                    <span class="font-semibold text-gray-700" data-dependencies-item-title></span>
                    unlocks.
                </p>

            </div>

            <button
                type="button"
                class="rounded-lg p-2 text-gray-500 transition hover:bg-gray-100"
                data-close-learning-item-dependencies-modal>

                <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                </svg>

            </button>

        </div>

        <!-- Body -->
        <div class="flex flex-col gap-5 p-8" data-dependencies-body>

            <!-- Allow dependencies -->
            <label class="flex cursor-pointer items-center justify-between gap-4 rounded-xl border border-gray-200 px-5 py-4">

                <span class="flex flex-col">
                    <span class="text-sm font-semibold text-gray-800">Allow dependencies</span>
                    <span class="text-xs text-gray-500">Keep this item locked until other items are completed.</span>
                </span>

                <span class="relative inline-flex shrink-0 items-center">
                    <input type="checkbox" class="peer sr-only" data-dependencies-enabled>
                    <span class="h-6 w-11 rounded-full bg-gray-300 transition peer-checked:bg-[#0040A6] peer-focus-visible:ring-2 peer-focus-visible:ring-[#0040A6]/30"></span>
                    <span class="absolute left-0.5 top-0.5 h-5 w-5 rounded-full bg-white shadow transition peer-checked:translate-x-5"></span>
                </span>

            </label>

            <!-- Modes -->
            <div class="hidden flex-col gap-3" data-dependencies-options>

                <label class="flex cursor-pointer items-start gap-3 rounded-xl border border-gray-200 px-5 py-4 transition has-[:checked]:border-[#0040A6] has-[:checked]:bg-[#0040A6]/5 has-[:disabled]:cursor-not-allowed has-[:disabled]:opacity-60">

                    <input type="radio" name="dependency_mode" value="previous" class="mt-1 accent-[#0040A6]" data-dependencies-mode>

                    <span class="flex flex-col">
                        <span class="text-sm font-semibold text-gray-800">Previous learning item</span>
                        <span class="text-xs text-gray-500" data-dependencies-previous-hint></span>
                    </span>

                </label>

                <label class="flex cursor-pointer items-start gap-3 rounded-xl border border-gray-200 px-5 py-4 transition has-[:checked]:border-[#0040A6] has-[:checked]:bg-[#0040A6]/5 has-[:disabled]:cursor-not-allowed has-[:disabled]:opacity-60">

                    <input type="radio" name="dependency_mode" value="custom" class="mt-1 accent-[#0040A6]" data-dependencies-mode>

                    <span class="flex flex-col">
                        <span class="text-sm font-semibold text-gray-800">Custom dependency</span>
                        <span class="text-xs text-gray-500">Pick one or more earlier items in this unit. All of them must be completed.</span>
                    </span>

                </label>

                <!-- Custom list -->
                <div class="hidden max-h-64 flex-col gap-2 overflow-y-auto rounded-xl bg-[#F9F9FC] p-3" data-dependencies-custom>

                    <div class="flex flex-col gap-1" data-dependencies-candidates></div>

                </div>

            </div>

            <p class="hidden text-sm font-semibold text-red-500" data-dependencies-error></p>

        </div>

        <!-- Footer -->
        <div class="flex justify-end gap-3 border-t border-gray-200 px-8 py-6">

            <button
                type="button"
                class="rounded-xl border border-gray-300 px-6 py-3 font-medium transition hover:bg-gray-100"
                data-close-learning-item-dependencies-modal>
                Cancel
            </button>

            <button
                type="button"
                data-dependencies-save
                class="rounded-xl bg-[#0040A6] px-6 py-3 font-semibold text-white transition hover:bg-[#002068] disabled:cursor-not-allowed disabled:opacity-60">
                Save
            </button>

        </div>

    </div>

</div>
