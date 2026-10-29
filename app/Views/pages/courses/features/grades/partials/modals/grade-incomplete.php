<!--
    Mark a student's grade Incomplete (INC): neither passed nor failed, with the
    reason and, optionally, the date to finish by. The grade itself is not
    changed. "Remove INC" takes it off again. Every change is recorded in the
    grade audit trail. Script: roster/incomplete-dialog.js.
-->
<div
    id="grade-incomplete-modal"
    class="fixed inset-0 z-50 hidden items-center justify-center bg-black/50 backdrop-blur-sm">

    <div class="w-full max-w-md rounded-2xl bg-white shadow-2xl">

        <div class="flex items-center justify-between border-b border-gray-200 px-8 py-6">
            <h2 class="text-xl font-bold text-brand">Incomplete (INC)</h2>
            <button type="button" class="rounded-lg p-2 text-gray-500 transition hover:bg-gray-100" aria-label="Close" data-close-grade-incomplete-modal>
                <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                </svg>
            </button>
        </div>

        <form id="grade-incomplete-form" class="flex flex-col gap-6 p-8">

            <input type="hidden" data-incomplete-student-id>

            <p class="text-sm text-gray-500">
                <span data-incomplete-student-name class="font-semibold text-[#646464]"></span> —
                an Incomplete is not a pass or a fail. The grade stays as it is until you remove the INC.
            </p>

            <div class="flex flex-col gap-2">
                <label for="incomplete-reason" class="text-sm font-semibold text-gray-700">Reason</label>
                <textarea id="incomplete-reason" data-incomplete-reason rows="3" placeholder="e.g. Medical leave, final project to be submitted"
                    class="rounded-xl border border-gray-300 px-4 py-3 text-sm outline-none transition focus:border-brand"></textarea>
            </div>

            <div class="flex flex-col gap-2">
                <label for="incomplete-until" class="text-sm font-semibold text-gray-700">
                    Finish by <span class="font-normal text-gray-500">(optional)</span>
                </label>
                <input id="incomplete-until" type="date" data-incomplete-until
                    class="rounded-xl border border-gray-300 px-4 py-3 outline-none transition focus:border-brand">
            </div>

            <div data-incomplete-error class="hidden rounded-xl bg-red-50 px-4 py-3 text-sm text-red-600" role="alert"></div>

        </form>

        <div class="flex justify-end gap-3 border-t border-gray-200 px-8 py-6">
            <button type="button" class="rounded-xl border border-gray-300 px-6 py-3 font-medium transition hover:bg-gray-100" data-close-grade-incomplete-modal>
                Cancel
            </button>
            <button type="button" class="hidden rounded-xl border border-[#C33939]/40 px-6 py-3 font-medium text-[#C33939] transition hover:bg-[#FDEDEC]" data-incomplete-remove>
                Remove INC
            </button>
            <button type="submit" form="grade-incomplete-form" class="rounded-xl bg-brand px-6 py-3 font-semibold text-white transition hover:bg-brand-dark" data-incomplete-submit>
                Mark Incomplete
            </button>
        </div>

    </div>

</div>
