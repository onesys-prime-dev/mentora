<?php

/*
|--------------------------------------------------------------------------
| Dialog — a modal over the page (shared/dialog.js)
|--------------------------------------------------------------------------
| Params: id (required), title (required), body (html, required), footer
| (html, usually the buttons), size (sm|md|lg), autoOpen (bool: open when the page
| loads, for a step the server has already prepared, such as a form for a chosen row),
| centered (bool: centered on a phone too, for a short question; confirm uses it),
| variant (default|sheet|fullscreen|menu), topAction (fullscreen only:
| ['label' => 'Post', 'form' => '<form id>'], the submit button in the phone top bar).
|
| Variants, below the breakpoint named; above it each is the centered dialog:
|   default     sits at the bottom, full width (sm)
|   sheet       a bottom sheet with a drag handle; swipe it down to close (sm)
|   fullscreen  fills the screen: Cancel | title | topAction on top; with a topAction, no footer (sm)
|   menu        a full-screen panel from the left (lg)
|
| Open it with <button data-dialog-open="<id>">. Put a form inside as
| form[data-dialog-form] to submit without leaving the page (the server answers
| JSON, see shared/dialog.js); without JavaScript the form posts normally.
| Primary button last, Cancel before it as <button data-dialog-close>.
|--------------------------------------------------------------------------
*/

$size      = $size ?? 'md';
$footer    = $footer ?? '';
$autoOpen  = $autoOpen ?? false;
$centered  = $centered ?? false;
$variant   = in_array($variant ?? 'default', ['sheet', 'fullscreen', 'menu'], true) ? $variant : 'default';
$topAction = $topAction ?? null;
// Only a well-formed ['label' => ..., 'form' => ...] on a fullscreen dialog renders (no warning on bad input).
$topAction = $variant === 'fullscreen' && is_array($topAction) && ($topAction['label'] ?? '') !== '' && ($topAction['form'] ?? '') !== '' ? $topAction : null;

// The menu becomes a centered dialog at lg, the others at sm.
$width = $variant === 'menu'
    ? match ($size) {
        'sm'    => 'lg:max-w-sm',
        'lg'    => 'lg:max-w-2xl',
        default => 'lg:max-w-md',
    }
    : match ($size) {
        'sm'    => 'sm:max-w-sm',
        'lg'    => 'sm:max-w-2xl',
        default => 'sm:max-w-md',
    };

$root = match (true) {
    $centered               => 'items-center justify-center p-4',
    $variant === 'fullscreen' => 'items-stretch justify-center p-0 sm:items-center sm:p-4',
    $variant === 'menu'       => 'items-stretch justify-start p-0 lg:items-center lg:justify-center lg:p-4',
    default                 => 'items-end justify-center p-0 sm:items-center sm:p-4',
};

$panel = match (true) {
    $centered               => 'max-h-full rounded-2xl',
    $variant === 'sheet'      => 'max-h-[min(90dvh,100%)] rounded-t-2xl max-sm:pt-3 max-sm:pb-safe-4 sm:rounded-2xl',
    $variant === 'fullscreen' => 'h-full max-h-full max-sm:pt-[max(1rem,env(safe-area-inset-top))] max-sm:pb-safe-4 sm:h-auto sm:rounded-2xl',
    $variant === 'menu'       => 'h-full max-h-full pt-[max(1.5rem,env(safe-area-inset-top))] lg:h-auto lg:rounded-2xl lg:pt-6',
    default                 => 'max-h-full rounded-t-2xl max-sm:pb-[max(1.5rem,env(safe-area-inset-bottom))] sm:rounded-2xl',
};
?>

<div id="<?= esc($id) ?>" data-dialog <?= $variant !== 'default' ? 'data-dialog-variant="' . $variant . '" ' : '' ?><?= $autoOpen ? 'data-dialog-autoopen ' : '' ?>hidden class="hidden fixed inset-0 z-50 bg-black/40 <?= $root ?>" role="dialog" aria-modal="true" aria-labelledby="<?= esc($id) ?>-title">

    <div data-dialog-panel class="flex w-full flex-col gap-5 overflow-y-auto bg-white p-6 shadow-xl <?= $panel ?> <?= $width ?>" tabindex="-1">

        <?php if ($variant === 'sheet'): ?>
            <div data-dialog-handle class="-my-2 flex justify-center py-2 sm:hidden" aria-hidden="true"><span class="h-1.5 w-10 rounded-full"></span></div>
        <?php endif; ?>

        <?php if ($variant === 'fullscreen'): ?>
            <div data-dialog-header class="grid grid-cols-[1fr_minmax(0,auto)_1fr] items-center gap-2 border-b border-black/10 pb-3 sm:flex sm:items-start sm:justify-between sm:gap-4 sm:border-0 sm:pb-0">
                <button type="button" data-dialog-close class="flex min-h-11 items-center justify-self-start rounded text-sm font-semibold text-brand transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/50 sm:order-last sm:justify-center sm:p-1 sm:pointer-fine:min-h-0 pointer-coarse:min-w-11 sm:text-[#A9A9A9] sm:hover:text-[#646464]">
                    <span class="sm:hidden">Cancel</span>
                    <span class="sr-only max-sm:hidden">Close</span>
                    <svg xmlns="http://www.w3.org/2000/svg" class="hidden h-5 w-5 sm:block" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                    </svg>
                </button>

                <h2 id="<?= esc($id) ?>-title" data-dialog-title class="min-w-0 truncate text-center text-base font-bold text-brand sm:text-left sm:text-lg"><?= esc($title) ?></h2>

                <?php if ($topAction): ?>
                    <button type="submit" form="<?= esc($topAction['form']) ?>" class="min-h-11 justify-self-end rounded-full bg-brand px-4 text-sm font-semibold text-white transition hover:bg-brand-dark disabled:cursor-not-allowed disabled:opacity-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/50 sm:hidden"><?= esc($topAction['label']) ?></button>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <div <?= $variant === 'sheet' ? 'data-dialog-header ' : '' ?>class="flex items-start justify-between gap-4">
                <h2 id="<?= esc($id) ?>-title" data-dialog-title class="text-lg font-bold text-brand"><?= esc($title) ?></h2>

                <button type="button" data-dialog-close class="flex items-center justify-center rounded p-1 text-[#A9A9A9] transition hover:text-[#646464] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/50 pointer-coarse:min-h-11 pointer-coarse:min-w-11" aria-label="Close">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                    </svg>
                </button>
            </div>
        <?php endif; ?>

        <div class="flex flex-col gap-4 text-sm text-[#3D3D3D]"><?= $body ?></div>

        <?php if ($footer !== ''): ?>
            <div class="<?= $topAction ? 'hidden sm:flex' : 'flex' ?> flex-wrap items-center justify-end gap-3"><?= $footer ?></div>
        <?php endif; ?>

    </div>

</div>
