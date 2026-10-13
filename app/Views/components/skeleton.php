<?php

/*
|--------------------------------------------------------------------------
| Skeleton — the loading state of a list or a card
|--------------------------------------------------------------------------
| Params: rows (default 5), label (what is loading, for screen readers).
| Never a blank page: while data loads, show the shape of what is coming.
| Pulses only when reduced motion is not requested (app.css).
|--------------------------------------------------------------------------
*/

$rows  = $rows ?? 5;
$label = $label ?? 'Loading';
?>

<div class="flex flex-col gap-3 rounded-2xl bg-white p-4 shadow-sm" role="status" aria-busy="true" data-skeleton>
    <span class="sr-only"><?= esc($label) ?>…</span>
    <?php for ($i = 0; $i < (int) $rows; $i++): ?>
        <div class="flex animate-pulse items-center gap-4" aria-hidden="true">
            <div class="h-4 w-4 rounded bg-gray-200"></div>
            <div class="h-4 flex-1 rounded bg-gray-200"></div>
            <div class="h-4 w-24 rounded bg-gray-200"></div>
            <div class="h-4 w-16 rounded bg-gray-200"></div>
        </div>
    <?php endfor; ?>
</div>
