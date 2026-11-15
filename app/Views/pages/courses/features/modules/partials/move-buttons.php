<?php

/*
|--------------------------------------------------------------------------
| ▲ ▼ move buttons for a module, learning unit or learning item row
| (move-buttons.js). They show only under a coarse pointer, where the drag
| handle hides; the first row's ▲ and the last row's ▼ are disabled by the script.
|
| Params: attr (data-module-move | data-learning-unit-move | data-learning-item-move),
| noun ("module"), title (the row's title, for the labels), class (extra classes, optional)
|--------------------------------------------------------------------------
*/
?>
<div class="hidden shrink-0 flex-col pointer-coarse:flex <?= esc($class ?? '', 'attr') ?>">
    <?php foreach (['up' => 'M5 15l7-7 7 7', 'down' => 'M19 9l-7 7-7-7'] as $direction => $path): ?>
        <button
            type="button"
            <?= $attr ?>="<?= $direction ?>"
            aria-label="Move <?= esc($noun, 'attr') ?> <?= esc($title, 'attr') ?> <?= $direction ?>"
            class="flex min-h-11 min-w-11 touch-manipulation items-center justify-center rounded-full text-[#646464] transition hover:bg-gray-100 hover:text-brand focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/50 disabled:cursor-not-allowed disabled:opacity-40">
            <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="<?= $path ?>"/>
            </svg>
        </button>
    <?php endforeach; ?>
</div>
