<footer class="mt-6 flex items-center justify-end border-t border-gray-200 pt-6">

    <!-- Actions -->
    <div class="flex items-center gap-3">

        <button
            type="button"
            data-delete-assessment
            data-assessment-id="<?= esc($currentAssessment['id']) ?>"
            title="Delete assessment"
            class="h-fit rounded-lg p-2 transition hover:bg-red-50">

            <svg xmlns="http://www.w3.org/2000/svg"
                class="h-6 w-6 text-[#767676] transition hover:text-red-500"
                fill="none"
                viewBox="0 0 24 24"
                stroke="currentColor"
                stroke-width="2">

                <path
                    stroke-linecap="round"
                    stroke-linejoin="round"
                    d="M4 7h16M9 7V5a2 2 0 012-2h2a2 2 0 012 2v2m3 0v12a2 2 0 01-2 2H8a2 2 0 01-2-2V7h12zM10 11v6M14 11v6" />
            </svg>

        </button>

        <button
            type="button"
            data-save-draft
            class="rounded-xl border border-gray-300 bg-white px-5 py-2.5 text-sm font-semibold text-gray-700 transition cursor-pointer hover:border-gray-400 hover:bg-gray-50">

            Save as Draft

        </button>

        <button
            type="button"
            data-publish
            class="rounded-xl bg-brand px-5 py-2.5 text-sm font-semibold text-white transition cursor-pointer hover:bg-brand-dark">

            Publish

        </button>

    </div>

</footer>