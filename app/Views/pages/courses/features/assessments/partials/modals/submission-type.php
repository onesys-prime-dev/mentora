<?php

$allowFileUpload = (bool) ($currentSettings['allow_file_upload'] ?? false);
$allowTextEntry = (bool) ($currentSettings['allow_text_entry'] ?? false);
$allowLinkEntry = (bool) ($currentSettings['allow_link_entry'] ?? false);
?>

<div
    id="submission-type-modal"
    class="fixed inset-0 z-50 hidden items-center justify-center bg-black/50 backdrop-blur-sm">

    <div class="w-full max-w-lg rounded-2xl bg-white shadow-2xl">

        <!-- Header -->
        <div class="flex items-center justify-between border-b border-gray-200 px-8 py-6">

            <div class="flex flex-col">

                <h2 class="text-2xl font-bold text-[#0040A6]">
                    Configure Submission Types
                </h2>

                <p class="mt-1 text-sm text-gray-500">
                    Choose how students are allowed to submit their work.
                </p>

            </div>

            <button
                type="button"
                class="rounded-lg p-2 text-gray-500 transition hover:bg-gray-100"
                data-close-submission-type-modal>

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
        <form
            id="submission-type-form"
            class="flex flex-col gap-3 p-8">

            <label class="flex cursor-pointer items-center gap-4 rounded-xl border border-gray-200 p-4 transition has-checked:border-[#0040A6] has-checked:bg-[#0040A6]/5">

                <input
                    type="checkbox"
                    name="allow_file_upload"
                    data-submission-type-option
                    data-checked="<?= $allowFileUpload ? '1' : '0' ?>"
                    class="h-5 w-5 shrink-0 accent-[#0040A6]"
                    <?= $allowFileUpload ? 'checked' : '' ?>>

                <svg
                    xmlns="http://www.w3.org/2000/svg"
                    class="h-6 w-6 shrink-0 text-[#0040A6]"
                    fill="none"
                    viewBox="0 0 24 24"
                    stroke="currentColor"
                    stroke-width="1.75">

                    <path
                        stroke-linecap="round"
                        stroke-linejoin="round"
                        d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />

                </svg>

                <div class="flex flex-col">

                    <span class="font-semibold text-[#5B5B5B]">
                        File Upload
                    </span>

                    <span class="text-xs text-gray-400">
                        Students upload a file as their submission.
                    </span>

                </div>

            </label>

            <label class="flex cursor-pointer items-center gap-4 rounded-xl border border-gray-200 p-4 transition has-checked:border-[#0040A6] has-checked:bg-[#0040A6]/5">

                <input
                    type="checkbox"
                    name="allow_text_entry"
                    data-submission-type-option
                    data-checked="<?= $allowTextEntry ? '1' : '0' ?>"
                    class="h-5 w-5 shrink-0 accent-[#0040A6]"
                    <?= $allowTextEntry ? 'checked' : '' ?>>

                <svg
                    xmlns="http://www.w3.org/2000/svg"
                    class="h-6 w-6 shrink-0 text-[#0040A6]"
                    fill="none"
                    viewBox="0 0 24 24"
                    stroke="currentColor"
                    stroke-width="1.75">

                    <path
                        stroke-linecap="round"
                        stroke-linejoin="round"
                        d="M4 6h16M4 12h16M4 18h10" />

                </svg>

                <div class="flex flex-col">

                    <span class="font-semibold text-[#5B5B5B]">
                        Text Entry
                    </span>

                    <span class="text-xs text-gray-400">
                        Students type their response directly.
                    </span>

                </div>

            </label>

            <label class="flex cursor-pointer items-center gap-4 rounded-xl border border-gray-200 p-4 transition has-checked:border-[#0040A6] has-checked:bg-[#0040A6]/5">

                <input
                    type="checkbox"
                    name="allow_link_entry"
                    data-submission-type-option
                    data-checked="<?= $allowLinkEntry ? '1' : '0' ?>"
                    class="h-5 w-5 shrink-0 accent-[#0040A6]"
                    <?= $allowLinkEntry ? 'checked' : '' ?>>

                <svg
                    xmlns="http://www.w3.org/2000/svg"
                    class="h-6 w-6 shrink-0 text-[#0040A6]"
                    fill="none"
                    viewBox="0 0 24 24"
                    stroke="currentColor"
                    stroke-width="1.75">

                    <path
                        stroke-linecap="round"
                        stroke-linejoin="round"
                        d="M13.828 10.172a4 4 0 010 5.656l-3 3a4 4 0 01-5.656-5.656l1.5-1.5M10.172 13.828a4 4 0 010-5.656l3-3a4 4 0 015.656 5.656l-1.5 1.5" />

                </svg>

                <div class="flex flex-col">

                    <span class="font-semibold text-[#5B5B5B]">
                        Link Entry
                    </span>

                    <span class="text-xs text-gray-400">
                        Students submit a URL (e.g. Google Docs, GitHub).
                    </span>

                </div>

            </label>

        </form>

        <!-- Footer -->
        <div class="flex justify-end gap-3 border-t border-gray-200 px-8 py-6">

            <button
                type="button"
                class="rounded-xl border border-gray-300 px-6 py-3 font-medium transition hover:bg-gray-100"
                data-close-submission-type-modal>

                Cancel

            </button>

            <button
                type="submit"
                form="submission-type-form"
                class="rounded-xl bg-[#0040A6] px-6 py-3 font-semibold text-white transition hover:bg-[#002068]">

                Save Changes

            </button>

        </div>

    </div>

</div>
