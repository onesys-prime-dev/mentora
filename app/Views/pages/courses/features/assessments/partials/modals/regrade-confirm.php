<div
    id="regrade-confirm-modal"
    class="fixed inset-0 z-50 hidden items-center justify-center bg-black/50 backdrop-blur-sm">

    <div class="w-full max-w-md rounded-2xl bg-white shadow-2xl">

        <div class="border-b border-gray-200 px-8 py-6">

            <h2 class="text-xl font-bold text-brand">
                Change this grade?
            </h2>

            <p class="mt-2 text-sm text-gray-500">
                The work goes back to <strong>Grading</strong>. Until you submit the new grade,
                the student will see it as “Being graded” and won’t see a score.
            </p>

            <p data-regrade-error class="mt-3 hidden text-sm font-semibold text-red-500"></p>

        </div>

        <div class="flex justify-end gap-3 border-t border-gray-200 px-8 py-6">

            <button
                type="button"
                data-close-regrade-modal
                class="rounded-xl border border-gray-300 px-5 py-2.5 hover:bg-gray-100">

                Cancel

            </button>

            <button
                type="button"
                data-confirm-regrade
                class="rounded-xl bg-brand px-5 py-2.5 font-semibold text-white transition hover:bg-brand-dark disabled:cursor-not-allowed disabled:opacity-60">

                Yes, change grade

            </button>

        </div>

    </div>

</div>
