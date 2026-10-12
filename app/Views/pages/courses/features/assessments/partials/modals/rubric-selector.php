<div
    id="rubric-selector-modal"
    class="fixed inset-0 z-50 hidden items-center justify-center bg-black/50 backdrop-blur-sm">

    <div class="flex max-h-[85vh] w-full max-w-xl flex-col rounded-2xl bg-white shadow-2xl">

        <!-- Header -->
        <div class="flex items-center justify-between border-b border-gray-200 px-8 py-6">

            <div class="flex flex-col">

                <h2 class="text-2xl font-bold text-brand">
                    Use Existing Rubric
                </h2>

                <p class="mt-1 text-sm text-gray-500">
                    Pick a rubric from another assignment to reuse here.
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
        <div class="flex-1 overflow-y-auto p-8">

            <div
                class="flex flex-col gap-3"
                data-rubric-list>

                <!-- populated via JS -->

            </div>

            <div
                class="hidden flex-col items-center gap-2 py-10 text-center"
                data-rubric-list-empty>

                <span class="text-sm font-medium text-gray-500">
                    No other rubrics found yet.
                </span>

                <span class="text-xs text-[#767676]">
                    Create a new rubric instead.
                </span>

            </div>

        </div>

        <!-- Footer -->
        <div class="flex items-center justify-between gap-3 border-t border-gray-200 px-8 py-6">

            <button
                type="button"
                class="text-sm font-semibold text-brand transition hover:underline"
                data-create-new-rubric>

                + Create a new rubric instead

            </button>

            <button
                type="button"
                class="rounded-xl border border-gray-300 px-6 py-3 font-medium transition hover:bg-gray-100"
                data-close-rubric-modal>

                Cancel

            </button>

        </div>

    </div>

</div>
