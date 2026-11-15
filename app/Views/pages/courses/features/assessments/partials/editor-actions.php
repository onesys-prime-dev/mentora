<?php // From lg the footer sticks as before (.sticky-bar-bottom). Below lg it is pinned to the window's bottom edge, edge to edge, opaque, above the home indicator: on a phone this is how an instructor publishes. ?>
<footer class="sticky-bar-bottom mt-6 flex items-center justify-end border-t border-gray-200 pt-6 max-lg:sticky max-lg:bottom-0 max-lg:z-20 max-lg:-mx-5 max-lg:px-4 max-lg:pt-2 max-lg:pb-safe-2 max-lg:shadow-[0_-4px_16px_rgb(0_0_0/0.06)] sm:max-lg:-mx-8 sm:max-lg:px-8">

    <!-- Actions -->
    <div class="flex items-center gap-3 max-lg:w-full">

        <button
            type="button"
            data-delete-assessment
            data-assessment-id="<?= esc($currentAssessment['id']) ?>"
            title="Delete assessment"
            aria-label="Delete assessment"
            class="flex h-fit shrink-0 items-center justify-center rounded-lg p-2 transition hover:bg-red-50 max-lg:min-h-11 max-lg:min-w-11 pointer-coarse:min-h-11 pointer-coarse:min-w-11">

            <svg xmlns="http://www.w3.org/2000/svg"
                class="h-6 w-6 text-[#646464] transition hover:text-red-500"
                fill="none"
                viewBox="0 0 24 24"
                stroke="currentColor"
                stroke-width="2"
                aria-hidden="true">

                <path
                    stroke-linecap="round"
                    stroke-linejoin="round"
                    d="M4 7h16M9 7V5a2 2 0 012-2h2a2 2 0 012 2v2m3 0v12a2 2 0 01-2 2H8a2 2 0 01-2-2V7h12zM10 11v6M14 11v6" />
            </svg>

        </button>

        <button
            type="button"
            data-save-draft
            class="rounded-xl border border-gray-300 bg-white px-5 py-2.5 text-sm font-semibold text-gray-700 transition cursor-pointer hover:border-gray-400 hover:bg-gray-50 max-lg:min-h-11 max-lg:flex-1 max-lg:px-3 pointer-coarse:min-h-11">

            Save as Draft

        </button>

        <button
            type="button"
            data-publish
            class="rounded-xl bg-brand px-5 py-2.5 text-sm font-semibold text-white transition cursor-pointer hover:bg-brand-dark max-lg:min-h-11 max-lg:flex-1 max-lg:px-3 pointer-coarse:min-h-11">

            Publish

        </button>

    </div>

</footer>