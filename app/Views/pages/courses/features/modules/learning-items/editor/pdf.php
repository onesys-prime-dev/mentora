<?php

$resource = $currentItem['resource'] ?? null;

$file = $resource['file'] ?? null;

$allowDownload = !empty($currentItem['content']['allow_download']);

helper('number');
?>

<div
    data-pdf-editor
    data-learning-item-id="<?= esc($currentItem['id']) ?>"
    class="flex flex-col gap-5">

    <div class="flex flex-col gap-5 rounded-2xl border border-gray-200 bg-white p-6 shadow-sm">

        <!-- Current file -->
        <div
            data-pdf-current
            class="<?= $file ? 'flex' : 'hidden' ?> items-center gap-3 rounded-xl border border-gray-100 bg-[#F9F9FC] p-3">

            <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-[#0040A6]/10">
                <?= icon('pdf', 'h-5 w-5 text-[#0040A6]') ?>
            </div>

            <div class="flex min-w-0 flex-1 flex-col">
                <span data-pdf-current-name class="truncate text-sm font-semibold text-[#1A1C1E]">
                    <?= esc($file['original_filename'] ?? '') ?>
                </span>
                <span data-pdf-current-size class="text-xs text-gray-400">
                    <?= $file ? esc(number_to_size((int) $file['file_size'])) : '' ?>
                </span>
            </div>

            <a
                data-pdf-current-link
                href="<?= esc($file['url'] ?? '#') ?>"
                target="_blank"
                rel="noopener noreferrer"
                class="shrink-0 rounded-lg border border-gray-200 px-3 py-1.5 text-xs font-semibold text-[#0040A6] transition hover:bg-[#0040A6]/5">
                Open
            </a>

        </div>

        <!-- Upload / replace -->
        <label
            data-pdf-dropzone
            class="flex min-h-40 cursor-pointer flex-col items-center justify-center gap-2 rounded-2xl border-2 border-dashed border-[#DBDBDB] bg-white p-8 text-center transition-colors hover:bg-[#F9F9FC] data-dragging:border-[#0040A6] data-dragging:bg-[#0040A6]/5">

            <input
                type="file"
                accept="application/pdf,.pdf"
                class="hidden"
                data-pdf-input>

            <?= icon('pdf', 'pointer-events-none h-10 w-10 opacity-40') ?>

            <span data-pdf-status class="pointer-events-none text-sm font-semibold text-[#646464]">
                <?= $file ? 'Drop a new PDF here to replace it' : 'Drag and drop a PDF here' ?>
            </span>

            <span class="pointer-events-none text-xs text-[#767676]">
                or click to browse · PDF only · up to 25 MB
            </span>

        </label>

        <p data-pdf-error class="hidden text-sm font-semibold text-red-500"></p>

        <!-- Allow download -->
        <label class="flex cursor-pointer items-center justify-between gap-4 rounded-xl border border-gray-200 px-5 py-4">

            <span class="flex flex-col">
                <span class="text-sm font-semibold text-gray-800">Allow students to download</span>
                <span class="text-xs text-gray-500">Shows a Download button in the PDF viewer.</span>
            </span>

            <span class="relative inline-flex shrink-0 items-center">
                <input type="checkbox" class="peer sr-only" data-pdf-allow-download <?= $allowDownload ? 'checked' : '' ?>>
                <span class="h-6 w-11 rounded-full bg-gray-300 transition peer-checked:bg-[#0040A6]"></span>
                <span class="absolute left-0.5 top-0.5 h-5 w-5 rounded-full bg-white shadow transition peer-checked:translate-x-5"></span>
            </span>

        </label>

    </div>

    <!-- Preview -->
    <section
        data-pdf-preview
        class="<?= $file ? '' : 'hidden' ?> w-full overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-sm">

        <div
            id="pdf-viewer"
            data-url="<?= esc($file['url'] ?? '') ?>"
            data-download-url="<?= $file ? esc(file_url((int) $file['id'], true)) : '' ?>"
            data-allow-download="<?= $allowDownload ? 'true' : 'false' ?>">
        </div>

    </section>

</div>
