<?php

/*
| The question types ($elements, from quiz-builder.php): the pane beside the questions from lg up, a side panel
| below lg (opened from the top bar at md–lg). A click adds the type at the end, a drag drops it where it lands.
*/
?>

<aside
    id="quiz-builder-palette"
    aria-label="Question types"
    data-side-panel
    data-builder-palette
    class="flex flex-col gap-3 max-lg:p-5 max-lg:pb-safe-4 lg:sticky lg:top-24 lg:w-[290px] lg:shrink-0 lg:self-start">

    <div class="flex items-center justify-between gap-2">

        <span class="text-xs font-bold uppercase tracking-wide text-[#646464]">
            Add a Question
        </span>

        <button
            type="button"
            data-side-panel-close
            aria-label="Close question types"
            class="rounded-lg p-2 text-gray-500 transition hover:bg-gray-100 pointer-coarse:p-3 lg:hidden">
            <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
            </svg>
        </button>

    </div>

    <div class="flex flex-col gap-2">

        <?php foreach ($elements as $element): ?>

            <div
                draggable="true"
                data-palette-item
                data-palette-type="<?= esc($element['type']) ?>"
                class="flex cursor-grab items-start gap-3 rounded-xl border border-gray-200 bg-white p-3 transition-colors active:cursor-grabbing hover:border-brand hover:bg-brand/5">

                <span class="mt-0.5 shrink-0 text-gray-300">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M8 6h.01M8 12h.01M8 18h.01M16 6h.01M16 12h.01M16 18h.01" />
                    </svg>
                </span>

                <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-brand/10 text-brand">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75">
                        <path stroke-linecap="round" stroke-linejoin="round" d="<?= $element['icon'] ?>" />
                    </svg>
                </span>

                <span class="flex flex-col gap-0.5">
                    <span class="text-sm font-semibold text-[#1A1C1E]">
                        <?= esc($element['label']) ?>
                    </span>
                    <span class="text-xs text-gray-500">
                        <?= esc($element['description']) ?>
                    </span>
                </span>

            </div>

        <?php endforeach; ?>

    </div>

    <span class="text-xs text-[#646464]">
        Drag a question type onto the canvas, or click it to add it to the end.
    </span>

    <div class="h-px bg-gray-200"></div>

    <span class="text-xs font-bold uppercase tracking-wide text-[#646464]">
        Build Faster
    </span>

    <div class="flex flex-col gap-2">

        <?php if (feature_enabled('ai.question_generation')) : ?>

        <button
            type="button"
            data-open-ai-generate
            class="flex items-center gap-3 rounded-xl border border-gray-200 bg-white p-3 text-left transition-colors hover:border-brand hover:bg-brand/5">

            <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-brand/10 text-brand">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M9.813 15.904L9 18.75l-.813-2.846a4.5 4.5 0 00-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 003.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 003.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 00-3.09 3.09zM18.259 8.715L18 9.75l-.259-1.035a3.375 3.375 0 00-2.455-2.456L14.25 6l1.036-.259a3.375 3.375 0 002.455-2.456L18 2.25l.259 1.035a3.375 3.375 0 002.456 2.456L21.75 6l-1.035.259a3.375 3.375 0 00-2.456 2.456z" />
                </svg>
            </span>

            <span class="flex flex-col gap-0.5">
                <span class="text-sm font-semibold text-[#1A1C1E]">Generate with AI</span>
                <span class="text-xs text-gray-500">Pick readings and PDFs from this quiz&#39;s learning unit.</span>
            </span>

        </button>

        <?php else : ?>

            <p class="rounded-xl border border-gray-200 bg-gray-50 p-3 text-xs text-gray-600">
                AI question generation is turned off for your institution.
            </p>

        <?php endif; ?>

    </div>

</aside>
