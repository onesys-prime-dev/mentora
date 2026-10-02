<?php

/*
|--------------------------------------------------------------------------
| Status badge — one pill for every status in the Course Workspace
|--------------------------------------------------------------------------
| Params: label (required), tone (success|warning|danger|info|neutral),
| icon (registry name — status never relies on colour alone).
|--------------------------------------------------------------------------
*/

$tone = $tone ?? 'neutral';
$icon = $icon ?? null;

$classes = match ($tone) {
    'success' => 'bg-[#E8F5EC] text-[#2E7D4F]',
    'warning' => 'bg-[#FFF1D6] text-[#946200]',
    'danger'  => 'bg-[#FDEDEC] text-[#C33939]',
    'info'    => 'bg-[#E0E5F2] text-[#002068]',
    default   => 'bg-gray-100 text-[#646464]',
};
?>

<span class="inline-flex shrink-0 items-center gap-1 rounded-full px-3 py-1 text-xs font-bold <?= $classes ?>">
    <?php if ($icon !== null): ?>
        <?= icon($icon, 'h-3 w-3 text-current') ?>
    <?php endif; ?>
    <?= esc($label) ?>
</span>
