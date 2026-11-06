<?php

/*
|--------------------------------------------------------------------------
| Copy to / Move to another course: one dialog with a few steps, driven by
| modals/content-transfer.js (which steps it shows depends on what is being
| copied or moved). Opened by any [data-transfer] button.
|--------------------------------------------------------------------------
*/

$select = 'w-full rounded-xl border border-gray-300 bg-white px-4 py-3 text-sm outline-none transition focus:border-brand disabled:cursor-not-allowed disabled:bg-gray-100';
$radio  = 'flex cursor-pointer items-center gap-3 rounded-xl border border-gray-200 px-5 py-3 text-sm font-medium text-gray-800 transition has-[:checked]:border-brand has-[:checked]:bg-brand/5';
?>
<div
    id="content-transfer-modal"
    role="dialog"
    aria-modal="true"
    aria-labelledby="content-transfer-title"
    class="fixed inset-0 z-50 hidden items-center justify-center bg-black/50 backdrop-blur-sm">

    <div class="flex max-h-[90vh] w-full max-w-xl flex-col rounded-2xl bg-white shadow-2xl">

        <div class="flex items-center justify-between border-b border-gray-200 px-8 py-6">

            <div class="flex min-w-0 flex-col">
                <h2 id="content-transfer-title" class="truncate text-2xl font-bold text-brand" data-transfer-title></h2>
                <p class="mt-1 text-sm text-gray-500" data-transfer-subtitle></p>
            </div>

            <button type="button" class="rounded-lg p-2 text-gray-500 transition hover:bg-gray-100" aria-label="Close" data-close-transfer-modal>
                <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                </svg>
            </button>

        </div>

        <div class="flex min-h-0 flex-1 flex-col gap-5 overflow-y-auto p-8">

            <span class="text-xs font-semibold uppercase tracking-wide text-gray-500" data-transfer-progress></span>

            <!-- Course -->
            <div data-transfer-step="course" class="hidden flex-col gap-3 [&:not(.hidden)]:flex">

                <span class="text-sm font-semibold text-gray-700">Which course?</span>

                <label class="<?= $radio ?>" data-transfer-this-row>
                    <input type="radio" name="transfer-scope" value="this" class="accent-brand" data-transfer-scope>
                    This course
                </label>

                <label class="<?= $radio ?>">
                    <input type="radio" name="transfer-scope" value="other" class="accent-brand" data-transfer-scope>
                    Another course
                </label>

                <label class="sr-only" for="content-transfer-offering">Course</label>
                <select id="content-transfer-offering" class="<?= $select ?>" data-transfer-offering></select>

                <p class="hidden text-sm text-[#946200]" data-transfer-no-other>You do not teach another course of your institution to move it to.</p>

            </div>

            <!-- Module -->
            <div data-transfer-step="module" class="hidden flex-col gap-3 [&:not(.hidden)]:flex">
                <label class="text-sm font-semibold text-gray-700" for="content-transfer-module">Which module?</label>
                <select id="content-transfer-module" class="<?= $select ?>" data-transfer-module></select>
                <p class="hidden text-sm text-[#946200]" data-transfer-no-modules></p>
            </div>

            <!-- Learning unit -->
            <div data-transfer-step="unit" class="hidden flex-col gap-3 [&:not(.hidden)]:flex">
                <label class="text-sm font-semibold text-gray-700" for="content-transfer-unit">Which learning unit?</label>
                <select id="content-transfer-unit" class="<?= $select ?>" data-transfer-unit></select>
                <p class="hidden text-sm text-[#946200]" data-transfer-no-units></p>
            </div>

            <!-- What to copy -->
            <div data-transfer-step="tree" class="hidden flex-col gap-3 [&:not(.hidden)]:flex">
                <span class="text-sm font-semibold text-gray-700">What to copy</span>
                <div class="flex max-h-72 flex-col gap-3 overflow-y-auto rounded-xl bg-[#F9F9FC] p-4" data-transfer-tree></div>
            </div>

            <!-- Confirm (move) -->
            <div data-transfer-step="confirm" class="hidden flex-col gap-3 [&:not(.hidden)]:flex">
                <p class="rounded-xl bg-[#FFF8E8] px-5 py-4 text-sm text-[#7A5600]" data-transfer-confirm-text></p>
            </div>

            <p role="alert" class="hidden text-sm font-semibold text-red-500" data-transfer-error></p>

        </div>

        <div class="flex items-center justify-between gap-3 border-t border-gray-200 px-8 py-6">

            <button type="button" class="hidden rounded-xl border border-gray-300 px-6 py-3 font-medium transition hover:bg-gray-100 disabled:opacity-60" data-transfer-back>
                Back
            </button>

            <div class="ml-auto flex gap-3">
                <button type="button" class="rounded-xl border border-gray-300 px-6 py-3 font-medium transition hover:bg-gray-100" data-close-transfer-modal>
                    Cancel
                </button>
                <button type="button" class="rounded-xl bg-brand px-6 py-3 font-semibold text-white transition hover:bg-brand-dark disabled:cursor-not-allowed disabled:opacity-60" data-transfer-next>
                    Next
                </button>
            </div>

        </div>

    </div>

</div>
