<?php

/*
|--------------------------------------------------------------------------
| Dialog — a modal over the page (shared/dialog.js)
|--------------------------------------------------------------------------
| Params: id (required), title (required), body (html, required), footer
| (html, usually the buttons), size (sm|md|lg).
|
| Open it with <button data-dialog-open="<id>">. Put a form inside as
| form[data-dialog-form] to submit without leaving the page (the server answers
| JSON, see shared/dialog.js); without JavaScript the form posts normally.
| Primary button last, Cancel before it as <button data-dialog-close>.
| On a small screen the dialog fills the width.
|--------------------------------------------------------------------------
*/

$size   = $size ?? 'md';
$footer = $footer ?? '';

$width = match ($size) {
    'sm'    => 'sm:max-w-sm',
    'lg'    => 'sm:max-w-2xl',
    default => 'sm:max-w-md',
};
?>

<div id="<?= esc($id) ?>" data-dialog hidden class="hidden fixed inset-0 z-50 items-end justify-center bg-black/40 p-0 sm:items-center sm:p-4" role="dialog" aria-modal="true" aria-labelledby="<?= esc($id) ?>-title">

    <div class="flex max-h-full w-full flex-col gap-5 overflow-y-auto rounded-t-2xl bg-white p-6 shadow-xl sm:rounded-2xl <?= $width ?>" tabindex="-1">

        <div class="flex items-start justify-between gap-4">
            <h2 id="<?= esc($id) ?>-title" data-dialog-title class="text-lg font-bold text-[#0040A6]"><?= esc($title) ?></h2>

            <button type="button" data-dialog-close class="rounded p-1 text-[#A9A9A9] transition hover:text-[#646464] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#0040A6]/50" aria-label="Close">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                </svg>
            </button>
        </div>

        <div class="flex flex-col gap-4 text-sm text-[#3D3D3D]"><?= $body ?></div>

        <?php if ($footer !== ''): ?>
            <div class="flex flex-wrap items-center justify-end gap-3"><?= $footer ?></div>
        <?php endif; ?>

    </div>

</div>
