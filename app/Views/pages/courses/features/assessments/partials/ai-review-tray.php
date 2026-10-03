<div
    id="ai-review-tray"
    data-ai-review-tray
    class="hidden flex-col gap-3 rounded-2xl border border-[#0040A6]/30 bg-[#F9FBFF] p-4">

    <div class="flex items-center justify-between">

        <div class="flex items-center gap-2">

            <span class="flex h-7 w-7 items-center justify-center rounded-full bg-[#0040A6]/10 text-[#0040A6]">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M9.813 15.904L9 18.75l-.813-2.846a4.5 4.5 0 00-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 003.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 003.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 00-3.09 3.09z" />
                </svg>
            </span>

            <span class="font-bold text-[#0040A6]">AI Review Tray</span>

            <span data-ai-tray-progress class="rounded-full bg-white px-2.5 py-0.5 text-xs font-semibold text-gray-500">
                0 of 0
            </span>

        </div>

        <div class="flex items-center gap-2">

            <button
                type="button"
                data-ai-tray-accept-all
                class="rounded-xl bg-[#0040A6] px-3 py-1.5 text-xs font-semibold text-white transition hover:bg-[#002068]">
                Accept All
            </button>

            <button
                type="button"
                data-ai-tray-dismiss
                title="Close tray"
                class="rounded-lg p-1.5 text-[#767676] transition hover:bg-gray-200" aria-label="Close tray">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                </svg>
            </button>

        </div>

    </div>

    <div data-ai-tray-generating class="hidden items-center gap-2 text-xs text-gray-500">
        <span data-ai-tray-progress-ring class="text-[#0040A6]"></span>
        <span data-ai-tray-generating-label>Generating more questions...</span>
    </div>

    <div data-ai-tray-stopped-early class="hidden items-start gap-2 rounded-xl bg-amber-50 px-3 py-2.5 text-xs text-amber-800">
        <svg xmlns="http://www.w3.org/2000/svg" class="mt-0.5 h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z" />
        </svg>
        <span>
            Stopped at <span data-ai-tray-stopped-early-count></span> — the AI ran out of genuinely distinct questions for this topic. Try adding reference material or broadening the topic to generate more.
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

    <div data-ai-tray-list class="flex flex-col gap-2"></div>

</div>
