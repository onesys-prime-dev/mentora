<?php

/*
|--------------------------------------------------------------------------
| "Specific students": pick which students of the section get this
| assessment. The list is filled when the modal opens (inspector/
| specific-students.js) and saved with Save.
|--------------------------------------------------------------------------
*/
?>
<div
    id="assignees-modal"
    role="dialog"
    aria-modal="true"
    aria-labelledby="assignees-title"
    class="fixed inset-0 z-50 hidden items-center justify-center bg-black/50 backdrop-blur-sm">

    <div class="flex max-h-[85vh] w-full max-w-lg flex-col rounded-2xl bg-white shadow-2xl">

        <div class="flex items-center justify-between border-b border-gray-200 px-8 py-6">

            <h2 id="assignees-title" class="text-xl font-bold text-brand">Choose students</h2>

            <button type="button" class="rounded-lg p-2 text-gray-500 transition hover:bg-gray-100" aria-label="Close" data-close-assignees-modal>
                <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                </svg>
            </button>

        </div>

        <div class="flex min-h-0 flex-1 flex-col gap-3 px-8 py-5">

            <div class="flex items-center justify-between gap-3">

                <label class="inline-flex cursor-pointer items-center gap-2 text-sm font-medium text-gray-700">
                    <input type="checkbox" data-assignees-all class="h-4 w-4 rounded border-gray-300 accent-brand">
                    Select all
                </label>

                <span data-assignees-summary class="text-xs text-[#767676]"></span>

            </div>

            <p data-assignees-error role="alert" class="min-h-4 text-xs font-medium text-[#C33939]"></p>

            <ul data-assignees-list class="flex min-h-0 flex-1 flex-col gap-1 overflow-y-auto"></ul>

        </div>

        <div class="flex items-center justify-end gap-3 border-t border-gray-200 px-8 py-5">

            <button type="button" data-close-assignees-modal class="rounded-xl border border-gray-300 px-4 py-2 text-sm font-semibold text-gray-700 transition hover:bg-gray-50">
                Cancel
            </button>

            <button type="button" data-assignees-save class="rounded-xl bg-brand px-5 py-2 text-sm font-semibold text-white transition hover:bg-brand-dark disabled:cursor-not-allowed disabled:opacity-60">
                Save
            </button>

        </div>

    </div>

</div>
