<div
    id="ai-review-tray"
    data-ai-review-tray
    class="hidden flex-col gap-3 rounded-2xl border border-brand/30 bg-[#F9FBFF] p-4">

    <div class="flex items-center justify-between">

        <div class="flex items-center gap-2">

            <span class="flex h-7 w-7 items-center justify-center rounded-full bg-brand/10 text-brand">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M9.813 15.904L9 18.75l-.813-2.846a4.5 4.5 0 00-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 003.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 003.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 00-3.09 3.09z" />
                </svg>
            </span>

            <span class="font-bold text-brand">AI Review Tray</span>

            <span data-ai-tray-progress class="rounded-full bg-white px-2.5 py-0.5 text-xs font-semibold text-gray-500">
                0 of 0
            </span>

        </div>

        <div class="flex items-center gap-2">

            <button
                type="button"
                data-ai-tray-accept-clear
                class="hidden rounded-xl bg-brand px-3 py-1.5 text-xs font-semibold text-white transition hover:bg-brand-dark disabled:cursor-not-allowed disabled:opacity-60">
                Accept all verified
            </button>

            <button
                type="button"
                data-ai-tray-accept-all
                class="rounded-xl bg-brand px-3 py-1.5 text-xs font-semibold text-white transition hover:bg-brand-dark">
                Accept All
            </button>

            <button
                type="button"
                data-ai-tray-dismiss
                title="Discard all and close"
                class="rounded-lg p-1.5 text-[#767676] transition hover:bg-gray-200" aria-label="Discard all questions and close the tray">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                </svg>
            </button>

        </div>

    </div>

    <div data-ai-tray-generating class="hidden items-center gap-2 text-xs text-gray-500">
        <span data-ai-tray-progress-ring class="text-brand"></span>
        <span data-ai-tray-generating-label>Generating more questions...</span>
        <button type="button" data-ai-tray-cancel class="ml-1 rounded-full px-2 py-0.5 text-xs font-medium text-gray-600 underline hover:text-gray-900">Cancel</button>
    </div>

    <div data-ai-tray-job-error role="alert" class="hidden items-start gap-2 rounded-xl bg-red-50 px-3 py-2.5 text-xs text-red-800">
        <span data-ai-tray-job-error-text></span>
    </div>

    <div data-ai-tray-stopped-early class="hidden items-start gap-2 rounded-xl bg-amber-50 px-3 py-2.5 text-xs text-amber-800">
        <svg xmlns="http://www.w3.org/2000/svg" class="mt-0.5 h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z" />
        </svg>
        <span>
            Stopped at <span data-ai-tray-stopped-early-count></span> — there was not enough distinct material to write more. Select more readings or PDFs of this unit to get more questions.
        </span>
    </div>

    <div data-ai-tray-skipped-sources class="hidden items-start gap-2 rounded-xl bg-amber-50 px-3 py-2.5 text-xs text-amber-800">
        <svg xmlns="http://www.w3.org/2000/svg" class="mt-0.5 h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z" />
        </svg>
        <span>
            Couldn't read <span data-ai-tray-skipped-sources-list></span>, so these questions don't cover it.
        </span>
    </div>

    <div data-ai-tray-coverage class="hidden flex-col gap-1 rounded-xl border border-gray-200 bg-white px-3 py-2.5 text-xs text-gray-700">
        <span class="font-semibold text-gray-800">Questions per reading</span>
        <ul data-ai-tray-coverage-list class="flex flex-col gap-0.5"></ul>
        <p data-ai-tray-truncated class="hidden text-amber-800">This unit is very long, so only the first part was read.</p>
    </div>

    <div data-ai-tray-shortfall class="hidden flex-col gap-2 rounded-xl bg-amber-50 px-3 py-2.5 text-xs text-amber-900">
        <span data-ai-tray-shortfall-text></span>
        <button type="button" data-ai-tray-top-up class="self-start rounded-xl bg-brand px-3 py-1.5 text-xs font-semibold text-white transition hover:bg-brand-dark disabled:cursor-not-allowed disabled:opacity-60">
            Write more
        </button>
    </div>

    <p data-ai-tray-message role="status" class="hidden rounded-xl bg-white px-3 py-2 text-xs font-medium text-gray-700"></p>

    <div data-ai-tray-list class="flex flex-col gap-2"></div>

</div>
