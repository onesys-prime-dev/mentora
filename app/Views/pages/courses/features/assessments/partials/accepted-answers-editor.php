<?php

/*
|--------------------------------------------------------------------------
| Short Answer answer key editor (server-rendered). Same markup as
| renderAcceptedAnswersEditor() in resources/js/.../questions/accepted-answers.js,
| which also handles its events. First answer = main answer; the rest are
| equivalent forms. Data: $acceptedAnswers (list), $caseSensitive (bool).
|--------------------------------------------------------------------------
*/

$acceptedAnswers ??= [];

$rows = $acceptedAnswers !== [] ? $acceptedAnswers : [''];
?>

<div class="flex flex-col gap-2" data-accepted-answers>

    <div class="flex items-center justify-between gap-2">
        <span class="text-[11px] font-bold uppercase tracking-wide text-emerald-700">Accepted answers</span>
        <span class="rounded-full bg-gray-100 px-2 py-0.5 text-[10px] font-bold text-gray-600 <?= !empty($caseSensitive) ? '' : 'hidden' ?>" data-case-sensitive-badge>Case sensitive</span>
    </div>

    <div class="flex flex-col gap-1.5" data-accepted-answers-list>

        <?php foreach ($rows as $index => $answer): ?>

            <div class="flex items-center gap-2 rounded-lg border border-emerald-200 bg-emerald-50/60 px-2.5 py-1.5" data-accepted-answer-row>

                <input
                    type="text"
                    data-accepted-answer
                    value="<?= esc($answer) ?>"
                    placeholder="<?= $index === 0 ? 'Correct answer' : 'Alternative answer' ?>"
                    class="min-w-0 flex-1 border-0 bg-transparent px-1 py-0.5 text-sm font-semibold text-emerald-700 outline-none placeholder:font-normal placeholder:text-emerald-700/40">

                <button type="button" data-remove-accepted-answer title="Remove" class="shrink-0 rounded-lg p-1 text-emerald-700/40 transition hover:bg-red-50 hover:text-red-500" aria-label="Remove">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" /></svg>
                </button>

            </div>

        <?php endforeach; ?>

    </div>

    <button type="button" data-add-accepted-answer class="self-start text-xs font-semibold text-brand transition hover:underline">+ Add alternative</button>

    <p class="rounded-lg border border-[#FFE3A3] bg-[#FFF8E6] px-3 py-2 text-xs text-[#946200] <?= $acceptedAnswers !== [] ? 'hidden' : '' ?>" data-accepted-answers-empty>
        Add the correct answer so this question can be auto-graded.
    </p>

</div>
