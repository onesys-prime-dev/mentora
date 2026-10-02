<?php

/*
|--------------------------------------------------------------------------
| Renders what a student handed in for one assignment attempt. The file
| downloads through the shared files/{id} route (FileController).
|--------------------------------------------------------------------------
*/

helper('number');

$attempt = $attempt ?? [];

$submissionText = trim((string) ($attempt['submission_text'] ?? ''));

$submissionLink = trim((string) ($attempt['submission_link'] ?? ''));

$isSafeLink = $submissionLink !== ''
    && in_array(strtolower((string) parse_url($submissionLink, PHP_URL_SCHEME)), ['http', 'https'], true);

$submissionFile = $attempt['file'] ?? null;

$hasContent = $submissionText !== '' || $submissionLink !== '' || $submissionFile;
?>

<div class="flex flex-col gap-3">

    <?php if ($submissionFile): ?>

        <div class="flex items-center gap-3 rounded-xl border border-gray-100 bg-[#F9F9FC] p-3">

            <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-[#0040A6]/10 text-[#0040A6]">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M7 21h10a2 2 0 002-2V9.414a1 1 0 00-.293-.707l-5.414-5.414A1 1 0 0012.586 3H7a2 2 0 00-2 2v14a2 2 0 002 2z" />
                </svg>
            </div>

            <div class="flex min-w-0 flex-1 flex-col">
                <span class="truncate text-sm font-semibold text-[#1A1C1E]">
                    <?= esc($submissionFile['original_filename']) ?>
                </span>
                <span class="text-xs text-gray-400">
                    <?= esc(number_to_size((int) $submissionFile['file_size'])) ?>
                </span>
            </div>

            <a
                href="<?= esc(file_url((int) $submissionFile['id'], true)) ?>"
                class="shrink-0 rounded-lg border border-gray-200 px-3 py-1.5 text-xs font-semibold text-[#0040A6] transition hover:bg-[#0040A6]/5">
                Download
            </a>

        </div>

    <?php endif; ?>

    <?php if ($submissionLink !== ''): ?>

        <div class="flex items-center gap-3 rounded-xl border border-gray-100 bg-[#F9F9FC] p-3">

            <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-[#0040A6]/10 text-[#0040A6]">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1" />
                </svg>
            </div>

            <?php if ($isSafeLink): ?>

                <a
                    href="<?= esc($submissionLink) ?>"
                    target="_blank"
                    rel="noopener noreferrer"
                    class="min-w-0 flex-1 truncate text-sm font-semibold text-[#0040A6] hover:underline">
                    <?= esc($submissionLink) ?>
                </a>

            <?php else: ?>

                <span class="min-w-0 flex-1 truncate text-sm text-gray-500">
                    <?= esc($submissionLink) ?>
                </span>

            <?php endif; ?>

        </div>

    <?php endif; ?>

    <?php if ($submissionText !== ''): ?>

        <div class="rounded-xl border border-gray-100 bg-white px-4 py-3 text-sm leading-relaxed text-gray-700">
            <?= nl2br(esc($submissionText)) ?>
        </div>

    <?php endif; ?>

    <?php if (!$hasContent): ?>

        <div class="rounded-xl border border-dashed border-gray-200 bg-[#F9F9FC] px-4 py-6 text-center text-xs text-gray-400">
            Nothing was attached to this submission.
        </div>

    <?php endif; ?>

</div>
