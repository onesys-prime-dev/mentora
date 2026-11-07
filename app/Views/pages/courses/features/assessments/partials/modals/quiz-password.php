<div
    id="quiz-password-modal"
    role="dialog"
    aria-modal="true"
    aria-labelledby="quiz-password-title"
    class="fixed inset-0 z-50 hidden items-center justify-center bg-black/50 backdrop-blur-sm">

    <div class="flex w-full max-w-md flex-col rounded-2xl bg-white shadow-2xl">

        <div class="flex items-center justify-between border-b border-gray-200 px-8 py-6">

            <h2 id="quiz-password-title" class="text-xl font-bold text-brand">Quiz password</h2>

            <button
                type="button"
                class="rounded-lg p-2 text-gray-500 transition hover:bg-gray-100"
                aria-label="Close"
                data-close-password-modal>

                <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                </svg>

            </button>

        </div>

        <div class="flex flex-col gap-3 px-8 py-6">

            <label for="quiz-password-field" class="text-xs font-semibold uppercase tracking-wide text-gray-500">
                Password
            </label>

            <input
                id="quiz-password-field"
                type="text"
                data-password-input
                autocomplete="off"
                spellcheck="false"
                maxlength="64"
                aria-describedby="quiz-password-help quiz-password-modal-error"
                class="rounded-lg border border-gray-300 px-3 py-2 font-mono text-sm outline-none transition focus:border-brand">

            <p id="quiz-password-help" class="text-xs text-[#767676]">
                A password is suggested. Keep it, or erase it and type your own (4 to 64 characters). Students type it exactly,
                capitals included. Write it down: once saved it is shown as ****** and cannot be read back.
            </p>

            <p id="quiz-password-modal-error" role="alert" data-password-error class="min-h-4 text-xs font-medium text-[#C33939]"></p>

            <button
                type="button"
                data-password-generate
                class="w-fit text-xs font-semibold text-brand hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/50">
                Suggest another
            </button>

        </div>

        <div class="flex items-center justify-end gap-3 border-t border-gray-200 px-8 py-5">

            <button
                type="button"
                data-close-password-modal
                class="rounded-xl border border-gray-300 px-4 py-2 text-sm font-semibold text-gray-700 transition hover:bg-gray-50">
                Cancel
            </button>

            <button
                type="button"
                data-password-save
                class="rounded-xl bg-brand px-5 py-2 text-sm font-semibold text-white transition hover:bg-brand-dark disabled:cursor-not-allowed disabled:opacity-60">
                Save
            </button>

        </div>

    </div>

</div>
