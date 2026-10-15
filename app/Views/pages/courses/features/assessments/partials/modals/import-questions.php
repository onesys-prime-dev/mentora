<div
    id="import-questions-modal"
    class="fixed inset-0 z-50 hidden items-center justify-center bg-black/50 backdrop-blur-sm">

    <div class="flex max-h-[88vh] w-full max-w-2xl flex-col rounded-2xl bg-white shadow-2xl">

        <div class="flex items-center justify-between border-b border-gray-200 px-8 py-6">

            <h2 class="text-xl font-bold text-brand">
                Import Questions
            </h2>

            <button
                type="button"
                class="rounded-lg p-2 text-gray-500 transition hover:bg-gray-100"
                aria-label="Close"
                data-close-import-questions-modal>

                <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                </svg>

            </button>

        </div>

        <ol class="flex items-center gap-2 border-b border-gray-100 px-8 py-3 text-xs font-semibold" aria-label="Import steps">
            <li data-import-stepper="upload" class="flex items-center gap-2"><span class="flex h-5 w-5 items-center justify-center rounded-full bg-brand text-[11px] text-white">1</span> Choose</li>
            <li class="h-px w-6 bg-gray-300" aria-hidden="true"></li>
            <li data-import-stepper="review" class="flex items-center gap-2 text-gray-400"><span class="flex h-5 w-5 items-center justify-center rounded-full bg-gray-200 text-[11px] text-gray-600">2</span> Review</li>
            <li class="h-px w-6 bg-gray-300" aria-hidden="true"></li>
            <li data-import-stepper="done" class="flex items-center gap-2 text-gray-400"><span class="flex h-5 w-5 items-center justify-center rounded-full bg-gray-200 text-[11px] text-gray-600">3</span> Done</li>
        </ol>

        <!-- Step 1: choose the file -->
        <div data-import-step="upload" class="flex flex-col gap-4 p-8">

            <p class="text-sm text-gray-600">
                Bring in questions from a Word, Rich Text, Excel, CSV or text file, or paste them. Your own exam works as it is:
                number the questions, letter the choices and give the answer. A quiz you exported from here
                and edited works too, and so do Aiken and GIFT files from other systems. Nothing is saved until you review and confirm.
            </p>

            <div class="inline-flex w-fit rounded-xl bg-gray-100 p-1" role="tablist">
                <button type="button" role="tab" data-import-source-tab="file" class="rounded-lg bg-white px-4 py-1.5 text-sm font-semibold text-brand shadow transition">Upload a file</button>
                <button type="button" role="tab" data-import-source-tab="text" class="rounded-lg px-4 py-1.5 text-sm font-semibold text-gray-500 transition">Paste text</button>
                <button type="button" role="tab" data-import-source-tab="quiz" class="rounded-lg px-4 py-1.5 text-sm font-semibold text-gray-500 transition">From another quiz</button>
            </div>

            <div data-import-source-panel="file" data-import-dropzone class="flex flex-col gap-2 rounded-xl border-2 border-dashed border-gray-200 p-4 transition">
                <input
                    type="file"
                    data-import-file-input
                    accept=".docx,.rtf,.xlsx,.csv,.txt,.pdf,.html,.htm"
                    aria-label="Quiz file to import"
                    class="rounded-lg border border-gray-300 px-3 py-2 text-sm outline-none transition focus:border-brand">

                <p data-import-file-name class="hidden text-xs font-semibold text-gray-700" aria-live="polite"></p>
                <p class="text-xs text-[#767676]">Drag a file here, or choose one.</p>
                <p class="text-xs text-[#767676]">
                    Word, Rich Text, PDF (with selectable text), HTML, Excel, CSV, or Aiken/GIFT/printed-exam text, up to 5 MB.
                    <a data-import-template-link href="#" class="font-semibold text-brand hover:underline">Download the Excel template</a>
                </p>
            </div>

            <div data-import-source-panel="text" class="hidden flex-col gap-2">
                <textarea
                    data-import-text-input
                    rows="8"
                    aria-label="Questions to import"
                    placeholder="1. Which layer routes packets?&#10;a) Transport&#10;b) Network&#10;Answer: b"
                    class="rounded-lg border border-gray-300 px-3 py-2 text-sm outline-none transition focus:border-brand"></textarea>
            </div>

            <div data-import-source-panel="quiz" class="hidden flex-col gap-3">

                <label class="flex flex-col gap-1 text-xs font-medium text-gray-600">
                    Quiz to take questions from
                    <select
                        data-picker-source
                        disabled
                        class="rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm font-normal text-gray-800 outline-none transition focus:border-brand disabled:bg-gray-50">
                    </select>
                </label>

                <div data-picker-tools class="hidden items-center gap-3">
                    <input
                        type="search"
                        data-picker-search
                        aria-label="Search these questions"
                        placeholder="Search these questions"
                        class="min-w-0 flex-1 rounded-lg border border-gray-300 px-3 py-2 text-sm outline-none transition focus:border-brand">
                    <button type="button" data-picker-select-all class="shrink-0 text-xs font-semibold text-brand hover:underline">Select all</button>
                </div>

                <div data-picker-list class="flex max-h-72 flex-col gap-1.5 overflow-y-auto"></div>

                <p data-picker-message class="hidden text-xs text-[#767676]" aria-live="polite"></p>

                <button
                    type="button"
                    data-picker-add
                    disabled
                    class="self-end rounded-xl bg-brand px-6 py-3 font-semibold text-white transition hover:bg-brand-dark disabled:cursor-not-allowed disabled:opacity-60">
                    Add questions
                </button>

            </div>

            <details data-import-layout-guide class="rounded-xl border border-gray-200 p-3 text-xs text-gray-600">
                <summary class="cursor-pointer font-semibold text-gray-700">What layout can I use?</summary>
                <div class="mt-2 flex flex-col gap-1.5">
                    <p><strong>Questions</strong> start with a number: <code>1.</code> <code>1)</code> <code>Q1:</code> <code>Question 1</code>.</p>
                    <p><strong>Choices</strong> start with a letter: <code>a)</code> <code>A.</code> <code>(a)</code>.</p>
                    <p><strong>The answer</strong> is a line like <code>Answer: b</code> (or <code>Answer: a, c</code>), a <code>*</code> after the right choice, or a list under &ldquo;Answer Key&rdquo; at the end.</p>
                    <p><strong>Points</strong> can be written <code>(2 pts)</code> or on a line <code>Points: 2</code>. No choices and an answer makes a short answer; no choices and no answer makes an essay.</p>
                    <p>
                        <a data-import-sample-link href="#" class="font-semibold text-brand hover:underline">Download a sample file</a>
                    </p>
                </div>
            </details>

            <div data-import-error role="alert" class="hidden rounded-xl bg-red-50 px-4 py-3 text-sm text-red-600"></div>

            <button
                type="button"
                data-import-preview-button
                class="self-end rounded-xl bg-brand px-6 py-3 font-semibold text-white transition hover:bg-brand-dark disabled:cursor-not-allowed disabled:opacity-60">
                Review questions
            </button>

        </div>

        <!-- Step 2: review -->
        <div data-import-step="review" class="hidden flex-1 flex-col gap-4 overflow-y-auto p-8">

            <p class="text-xs font-medium text-[#767676]">Nothing has been saved yet.</p>

            <div class="grid grid-cols-2 gap-3 text-center sm:grid-cols-4">

                <div class="rounded-xl bg-emerald-50 p-3">
                    <span class="block text-2xl font-bold text-emerald-600" data-import-added-count>0</span>
                    <span class="text-xs font-semibold text-emerald-700">New</span>
                </div>

                <div class="rounded-xl bg-amber-50 p-3">
                    <span class="block text-2xl font-bold text-amber-600" data-import-changed-count>0</span>
                    <span class="text-xs font-semibold text-amber-700">Changed</span>
                </div>

                <div class="rounded-xl bg-gray-100 p-3">
                    <span class="block text-2xl font-bold text-gray-700" data-import-removed-count>0</span>
                    <span class="text-xs font-semibold text-gray-600">Not in the file</span>
                </div>

                <div class="rounded-xl bg-red-50 p-3">
                    <span class="block text-2xl font-bold text-red-600" data-import-problem-count>0</span>
                    <span class="text-xs font-semibold text-red-700">Can't import</span>
                </div>

            </div>

            <div data-import-problems class="hidden flex-col gap-1 rounded-xl border border-amber-200 bg-amber-50 p-3 text-xs text-amber-900"></div>

            <div data-import-diff-list class="flex flex-col gap-2"></div>

            <div data-import-removed-section class="hidden flex-col gap-2 rounded-xl border border-gray-200 p-3">

                <div class="flex items-center justify-between gap-3">
                    <span class="text-xs font-semibold text-gray-700">In the quiz but not in the file</span>
                    <button type="button" data-import-remove-all class="text-xs font-semibold text-brand hover:underline">Select all</button>
                </div>

                <p class="text-xs text-[#767676]">
                    These stay in the quiz unless you tick them. Ticked questions are deleted when you confirm.
                </p>

                <div data-import-removed-list class="flex flex-col gap-1"></div>

            </div>

            <label data-import-responses-warning class="hidden items-start gap-2 rounded-xl border border-red-200 bg-red-50 p-3 text-xs text-red-800">
                <input type="checkbox" data-import-acknowledge class="mt-0.5 h-4 w-4 shrink-0 accent-red-600">
                <span data-import-responses-text></span>
            </label>

        </div>

        <!-- Step 3: done -->
        <div data-import-step="done" class="hidden flex-1 flex-col gap-4 overflow-y-auto p-8">

            <p class="text-base font-semibold text-gray-800" data-import-result-message></p>

            <div data-import-skipped class="hidden flex-col gap-1 rounded-xl border border-amber-200 bg-amber-50 p-3 text-xs text-amber-900"></div>

            <div class="flex flex-wrap items-center gap-3">

                <button
                    type="button"
                    data-import-undo-button
                    class="rounded-xl border border-gray-300 px-5 py-2.5 text-sm font-semibold text-gray-700 transition hover:bg-gray-100 disabled:cursor-not-allowed disabled:opacity-60">
                    Undo this import
                </button>

                <span class="text-xs text-[#767676]">You can undo for the next 10 minutes.</span>

            </div>

        </div>

        <div class="flex items-center justify-between gap-3 border-t border-gray-200 px-8 py-6">

            <span data-import-status class="text-xs text-[#767676]" aria-live="polite"></span>

            <div class="flex gap-3">

                <button
                    type="button"
                    class="rounded-xl border border-gray-300 px-6 py-3 font-medium transition hover:bg-gray-100"
                    data-close-import-questions-modal>
                    Close
                </button>

                <button
                    type="button"
                    data-import-apply-button
                    class="hidden rounded-xl bg-brand px-6 py-3 font-semibold text-white transition hover:bg-brand-dark disabled:cursor-not-allowed disabled:opacity-60">
                    Import
                </button>

            </div>

        </div>

    </div>

</div>
