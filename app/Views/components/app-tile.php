<?php

/*
|--------------------------------------------------------------------------
| One global app tile (see global_apps() in navigation_helper.php).
|--------------------------------------------------------------------------
| $app      array{key, label, icon, url}
| $variant  'dashboard' (Quick Access card) | 'menu' (header app menu)
| $active   bool — the page you're on
|
| An app without a url isn't built yet: same icon and label, but no link
| and no hover, so it doesn't read as clickable.
*/

$variant ??= 'dashboard';
$active ??= false;

$isLive = $app['url'] !== null;

$tag = $isLive ? 'a' : 'span';

$square = $variant === 'menu'
    ? 'w-16 h-16 rounded-2xl'
    : 'w-full aspect-square rounded-3xl';

$iconClass = $variant === 'menu'
    ? 'h-6 w-6 text-[#0040A6]'
    : 'w-8 h-8 text-blue-800';

$surface = match (true) {
    $active => 'bg-[#0040A6]/10 border-[#0040A6]/30',
    $variant === 'menu' => 'bg-white border-black/5',
    default => 'bg-white/30 border-black/5',
};

$hover = $isLive && !$active
    ? ($variant === 'menu' ? 'group-hover:bg-[#F2F7FF]' : 'group-hover:bg-white/60')
    : '';
?>

<<?= $tag ?>
    class="flex flex-col items-center gap-2 <?= $isLive ? 'group' : 'cursor-default' ?>"
    <?php if ($isLive): ?>
        href="<?= esc($app['url'], 'attr') ?>"
        <?= $active ? 'aria-current="page"' : '' ?>
    <?php else: ?>
        aria-disabled="true"
        title="Coming soon"
    <?php endif; ?>
    data-app-tile="<?= esc($app['key'], 'attr') ?>">

    <span class="<?= $square ?> flex justify-center items-center border shadow-md transition <?= $surface ?> <?= $hover ?>">
        <?= icon($app['icon'], $iconClass) ?>
    </span>

    <span class="text-[11px] leading-tight text-center <?= $active ? 'font-semibold text-[#0040A6]' : 'text-gray-700' ?>">
        <?= esc($app['label']) ?>
    </span>

</<?= $tag ?>>
