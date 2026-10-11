<?php

/*
|--------------------------------------------------------------------------
| Lock note — why something cannot be changed here
|--------------------------------------------------------------------------
| Params: kind (sis|read_only|closed), text (optional: overrides the wording).
| One look everywhere, so people learn it: a lock icon and a sentence that says
| who owns the thing and what to do instead.
|--------------------------------------------------------------------------
*/

$text = $text ?? '';

$words = match ($kind) {
    'sis'       => 'Managed by your student information system. Change it there; the next sync brings it here.',
    'read_only' => 'This institution is read-only, so nothing can be changed. You can still look at everything and export it.',
    default     => 'This is closed, so it cannot be changed. Reopen it first if you need to.',
};
?>

<p class="flex items-start gap-2 rounded-xl bg-gray-50 px-3 py-2 text-xs text-[#646464]" data-lock="<?= esc($kind) ?>">
    <?= icon('lock', 'mt-0.5 h-3.5 w-3.5 text-[#646464]') ?>
    <span><?= esc($text !== '' ? $text : $words) ?></span>
</p>
