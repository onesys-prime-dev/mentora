<?php

/*
| The Quiz Builder's phone bar (below md): sticky on the bottom edge with the live save status (the builder saves as
| it goes) and "＋ Add question", which opens the question types upward inside the bar (shared/action-bar.js). Each
| type is a [data-palette-item], so a tap adds it exactly as a click on the palette does. $elements: the types.
*/
?>

<div
    data-action-bar
    data-quiz-builder-bar
    class="sticky bottom-0 z-20 -mx-5 bg-white px-4 pt-2 pb-safe-2 shadow-[0_-4px_16px_rgb(0_0_0/0.06)] sm:-mx-8 md:hidden">

    <div id="quiz-builder-add-panel" data-action-bar-panel>

        <div>

            <div class="flex flex-col gap-2 px-4 pt-3 pb-2">

                <span class="text-xs font-bold uppercase tracking-wide text-[#646464]">
                    Add a Question
                </span>

                <?php foreach ($elements as $element): ?>

                    <button
                        type="button"
                        data-palette-item
                        data-palette-type="<?= esc($element['type']) ?>"
                        class="flex min-h-11 w-full items-center gap-3 rounded-xl border border-gray-200 bg-white px-3 py-2 text-left transition-colors hover:border-brand hover:bg-brand/5">

                        <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-brand/10 text-brand">
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="<?= $element['icon'] ?>" />
                            </svg>
                        </span>

                        <span class="text-sm font-semibold text-[#1A1C1E]"><?= esc($element['label']) ?></span>

                    </button>

                <?php endforeach; ?>

                <?php if (feature_enabled('ai.question_generation')) : ?>

                    <span class="mt-2 text-xs font-bold uppercase tracking-wide text-[#646464]">
                        Build Faster
                    </span>

                    <button
                        type="button"
                        data-open-ai-generate
                        class="flex min-h-11 w-full items-center gap-3 rounded-xl border border-gray-200 bg-white px-3 py-2 text-left transition-colors hover:border-brand hover:bg-brand/5">

                        <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-brand/10 text-brand">
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M9.813 15.904L9 18.75l-.813-2.846a4.5 4.5 0 00-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 003.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 003.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 00-3.09 3.09zM18.259 8.715L18 9.75l-.259-1.035a3.375 3.375 0 00-2.455-2.456L14.25 6l1.036-.259a3.375 3.375 0 002.455-2.456L18 2.25l.259 1.035a3.375 3.375 0 002.456 2.456L21.75 6l-1.035.259a3.375 3.375 0 00-2.456 2.456z" />
                            </svg>
                        </span>

                        <span class="text-sm font-semibold text-[#1A1C1E]">Generate with AI</span>

                    </button>

                <?php endif; ?>

            </div>

        </div>

    </div>

    <div class="flex items-center gap-3">

        <div
            data-builder-save-status
            class="flex min-w-0 flex-1 items-center gap-2 text-sm text-gray-500">

            <div class="h-2 w-2 rounded-full bg-emerald-500" data-builder-save-indicator></div>

            <span class="truncate" data-builder-save-message>All changes saved</span>

        </div>

        <button
            type="button"
            data-action-bar-toggle
            aria-controls="quiz-builder-add-panel"
            aria-expanded="false"
            class="inline-flex min-h-11 shrink-0 items-center gap-1.5 rounded-xl bg-brand px-4 text-sm font-semibold text-white transition hover:bg-brand-dark">
            <span aria-hidden="true">＋</span> Add question
        </button>

    </div>

</div>
