<?php

/*
|--------------------------------------------------------------------------
| Skeleton — the loading state of a list or a card
|--------------------------------------------------------------------------
| Params: rows (default 5), variant ('rows' | 'list': phone list rows with avatar, two lines and a badge), label (what is loading, for screen readers).
| Never a blank page: while data loads, show the shape of what is coming.
| Pulses only when reduced motion is not requested (app.css).
|--------------------------------------------------------------------------
*/

$rows  = $rows ?? 5;
$label = $label ?? 'Loading';
$variant = $variant ?? 'rows';
?>

<div class="flex flex-col gap-3 rounded-2xl bg-white p-4 shadow-sm" role="status" aria-busy="true" data-skeleton>
    <span class="sr-only"><?= esc($label) ?>…</span>
    <?php for ($i = 0; $i < (int) $rows; $i++): ?>
        <?php if ($variant === 'list'): ?>
        <div class="flex animate-pulse items-center gap-3" aria-hidden="true">
            <div class="h-10 w-10 shrink-0 rounded-full bg-gray-200"></div>
            <div class="flex flex-1 flex-col gap-2">
                <div class="h-4 w-3/5 rounded bg-gray-200"></div>
                <div class="h-3 w-2/5 rounded bg-gray-200"></div>
            </div>
            <div class="h-5 w-12 rounded-full bg-gray-200"></div>
        </div>
        <?php else: ?>
        <div class="flex animate-pulse items-center gap-4" aria-hidden="true">
            <div class="h-4 w-4 rounded bg-gray-200"></div>
            <div class="h-4 flex-1 rounded bg-gray-200"></div>
            <div class="h-4 w-24 rounded bg-gray-200"></div>
            <div class="h-4 w-16 rounded bg-gray-200"></div>
        </div>
        <?php endif; ?>
    <?php endfor; ?>
</div>
