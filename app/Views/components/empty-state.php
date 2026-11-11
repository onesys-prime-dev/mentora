<?php

/*
|--------------------------------------------------------------------------
| Empty state — the one "nothing here yet" pattern
|--------------------------------------------------------------------------
| Params: title (required), text, icon (registry name), action (html; full width on a phone).
|--------------------------------------------------------------------------
*/

$text = $text ?? '';
$icon = $icon ?? null;
$action = $action ?? '';
?>

<div class="flex flex-col items-center justify-center gap-2 rounded-2xl border border-dashed border-gray-200 px-8 py-10 sm:py-14 text-center">

    <?php if ($icon !== null): ?>
        <?= icon($icon, 'h-10 w-10 text-gray-300') ?>
    <?php endif; ?>

    <span class="text-sm font-semibold text-[#646464]"><?= esc($title) ?></span>

    <?php if ($text !== ''): ?>
        <span class="text-xs text-[#646464]"><?= esc($text) ?></span>
    <?php endif; ?>

    <?php if ($action !== ''): ?>
        <div class="mt-2 flex max-sm:w-full max-sm:*:flex-1 max-sm:*:justify-center"><?= $action ?></div>
    <?php endif; ?>

</div>
