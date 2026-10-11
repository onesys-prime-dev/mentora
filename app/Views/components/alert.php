<?php

/*
|--------------------------------------------------------------------------
| Alert — an inline message on a page
|--------------------------------------------------------------------------
| Params: tone (success|warning|danger|info), text (required), title.
| Danger is announced at once (role="alert"); the rest politely (role="status").
| Status never relies on colour alone: the icon and the word for screen
| readers carry it too.
|--------------------------------------------------------------------------
*/

$tone  = $tone ?? 'info';
$title = $title ?? '';

[$surface, $iconName, $word] = match ($tone) {
    'success' => ['border-[#2E7D4F]/30 bg-[#E8F5EC] text-[#1F5A38]', 'check', 'Done'],
    'warning' => ['border-[#946200]/30 bg-[#FFF1D6] text-[#6F4A00]', 'attention', 'Warning'],
    'danger'  => ['border-[#C33939]/30 bg-[#FDEDEC] text-[#8E2424]', 'warning', 'Problem'],
    default   => ['border-[#0040A6]/20 bg-[#F2F7FF] text-[#002068]', 'info', 'Note'],
};
?>

<div class="flex items-start gap-3 rounded-2xl border px-4 py-3 text-sm <?= $surface ?>" role="<?= $tone === 'danger' ? 'alert' : 'status' ?>" data-alert="<?= esc($tone) ?>">
    <?= icon($iconName, 'mt-0.5 h-4 w-4 text-current') ?>
    <div class="min-w-0">
        <span class="sr-only"><?= esc($word) ?>: </span>
        <?php if ($title !== ''): ?><p class="font-semibold"><?= esc($title) ?></p><?php endif; ?>
        <p><?= esc($text) ?></p>
    </div>
</div>
