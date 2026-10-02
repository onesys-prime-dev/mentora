<?php

$elements = [
    [
        'type' => 'Multiple Choice',
        'label' => 'Multiple Choice',
        'description' => 'Students pick one correct answer.',
        'icon' => 'M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z',
    ],
    [
        'type' => 'Short Answer',
        'label' => 'Short Answer',
        'description' => 'Students type a brief response.',
        'icon' => 'M4 6h16M4 12h10',
    ],
    [
        'type' => 'Essay',
        'label' => 'Essay',
        'description' => 'Students write a longer response.',
        'icon' => 'M4 6h16M4 10h16M4 14h16M4 18h10',
    ],
    [
        'type' => 'File Upload',
        'label' => 'File Upload',
        'description' => 'Students upload a file as their answer.',
        'icon' => 'M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z',
    ],
];
?>

<div
    id="quiz-builder-palette"
    class="sticky top-6 flex w-[290px] shrink-0 flex-col gap-3 self-start"
    data-builder-palette>

    <span class="text-xs font-bold uppercase tracking-wide text-gray-400">
        Add a Question
    </span>

    <div class="flex flex-col gap-2">

        <?php foreach ($elements as $element): ?>

            <div
                draggable="true"
                data-palette-item
                data-palette-type="<?= esc($element['type']) ?>"
                class="flex cursor-grab items-start gap-3 rounded-xl border border-gray-200 bg-white p-3 transition-colors active:cursor-grabbing hover:border-[#0040A6] hover:bg-[#0040A6]/5">

                <span class="mt-0.5 shrink-0 text-gray-300">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M8 6h.01M8 12h.01M8 18h.01M16 6h.01M16 12h.01M16 18h.01" />
                    </svg>
                </span>

                <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-[#0040A6]/10 text-[#0040A6]">
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

    <span class="text-xs text-gray-400">
        Drag a question type onto the canvas, or click it to add it to the end.
    </span>

    <div class="h-px bg-gray-200"></div>

    <span class="text-xs font-bold uppercase tracking-wide text-gray-400">
        Build Faster
    </span>

    <div class="flex flex-col gap-2">

        <button
            type="button"
            data-open-ai-generate
            class="flex items-center gap-3 rounded-xl border border-gray-200 bg-white p-3 text-left transition-colors hover:border-[#0040A6] hover:bg-[#0040A6]/5">

            <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-[#0040A6]/10 text-[#0040A6]">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M9.813 15.904L9 18.75l-.813-2.846a4.5 4.5 0 00-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 003.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 003.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 00-3.09 3.09zM18.259 8.715L18 9.75l-.259-1.035a3.375 3.375 0 00-2.455-2.456L14.25 6l1.036-.259a3.375 3.375 0 002.455-2.456L18 2.25l.259 1.035a3.375 3.375 0 002.456 2.456L21.75 6l-1.035.259a3.375 3.375 0 00-2.456 2.456z" />
                </svg>
            </span>

            <span class="flex flex-col gap-0.5">
                <span class="text-sm font-semibold text-[#1A1C1E]">Generate with AI</span>
                <span class="text-xs text-gray-500">From a topic, pasted text, a file, or a learning unit.</span>
            </span>

        </button>

    </div>

</div>
