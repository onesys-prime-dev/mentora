<div
    id="module-delete-modal"
    class="fixed inset-0 z-50 hidden items-center justify-center bg-black/50 backdrop-blur-sm">

    <div class="w-full max-w-md rounded-2xl bg-white shadow-2xl">

        <div class="border-b border-gray-200 px-8 py-6">

            <h2 class="text-xl font-bold text-red-600">
                Delete Module
            </h2>

            <p class="mt-2 text-sm text-gray-500">
                Are you sure you want to delete this module?
                This action cannot be undone.
            </p>

            <p class="mt-3 rounded-xl bg-red-50 px-4 py-3 text-sm text-red-600">
                All of its learning units and items are deleted too — including its quizzes and
                assignments, the students' submissions to them, and their uploaded files.
            </p>

        </div>

        <form
            id="module-delete-form">

            <?= csrf_field() ?>

            <input
                type="hidden"
                name="module_id">

        </form>

        <div class="flex justify-end gap-3 px-8 py-6 border-t border-gray-200">

            <button
                type="button"
                data-close-module-delete-modal
                class="rounded-xl border border-gray-300 px-5 py-2.5 hover:bg-gray-100">

                Cancel

            </button>

            <button
                type="submit"
                form="module-delete-form"
                class="rounded-xl bg-red-600 px-5 py-2.5 font-semibold text-white hover:bg-red-700">

                Delete Module

            </button>

        </div>

    </div>

</div>