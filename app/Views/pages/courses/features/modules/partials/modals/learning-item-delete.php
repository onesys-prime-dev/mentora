<div
    id="learning-item-delete-modal"
    class="fixed inset-0 z-50 hidden items-center justify-center bg-black/50 backdrop-blur-sm">

    <div class="w-full max-w-md rounded-2xl bg-white shadow-2xl">

        <div class="border-b border-gray-200 px-8 py-6">

            <h2 class="text-xl font-bold text-red-600">
                Delete Learning Item
            </h2>

            <p class="mt-2 text-sm text-gray-500">
                Are you sure you want to delete this learning item?
                This action cannot be undone.
            </p>

        </div>

        <div class="flex justify-end gap-3 border-t border-gray-200 px-8 py-6">

            <button
                type="button"
                data-close-learning-item-delete-modal
                class="rounded-xl border border-gray-300 px-5 py-2.5 hover:bg-gray-100">

                Cancel

            </button>

            <button
                type="button"
                data-confirm-delete-learning-item
                class="rounded-xl bg-red-600 px-5 py-2.5 font-semibold text-white hover:bg-red-700">

                Delete Learning Item

            </button>

        </div>

    </div>

</div>
