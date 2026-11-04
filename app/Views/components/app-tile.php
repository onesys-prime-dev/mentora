<?php

/*
|--------------------------------------------------------------------------
| One global app tile (see global_apps() in navigation_helper.php).
|--------------------------------------------------------------------------
| $app      array{key, label, icon, url, badge?: int, badge_label?: string}
| $variant  'dashboard' (Quick Access card) | 'menu' (header app menu)
| $active   bool — the page you're on
|
| An app without a url isn't built yet: same icon and label, but no link
| and no hover, so it doesn't read as clickable.
|
| A badge (the Grades tile's count, GradesBadge) is a pill on the square's
| corner: the number ("99+" from 100) for the eye, and badge_label ("3
| waiting to grade") for screen readers and on hover. 0 draws nothing.
| The Messages tile's unread count is drawn even at 0 (hidden), since
| shared/messages-badge.js keeps it fresh after the page loads.
*/

$variant ??= 'dashboard';
$active ??= false;

$isLive = $app['url'] !== null;

$tag = $isLive ? 'a' : 'span';

$square = $variant === 'menu'
    ? 'w-16 h-16 rounded-2xl'
    : 'w-full aspect-square rounded-3xl';

$iconClass = $variant === 'menu'
    ? 'h-6 w-6 text-brand'
    : 'w-8 h-8 text-blue-800';

$surface = match (true) {
    $active => 'bg-brand/10 border-brand/30',
    $variant === 'menu' => 'bg-white border-black/5',
    default => 'bg-white/30 border-black/5',
};

$badge = (int) ($app['badge'] ?? 0);

$hover = $isLive && !$active
    ? ($variant === 'menu' ? 'group-hover:bg-brand-tint' : 'group-hover:bg-white/60')
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

    <span class="relative <?= $square ?> flex justify-center items-center border shadow-md transition <?= $surface ?> <?= $hover ?>">
        <?= icon($app['icon'], $iconClass) ?>

        <?php if ($app['key'] === 'messages' && isset($app['badge'])): ?>
            <?php // The count at load; shared/messages-badge.js keeps it fresh from data-url. ?>
            <span data-messages-badge data-url="<?= esc(site_url('messages/api/unread-count')) ?>" <?= (int) $app['badge'] > 0 ? '' : 'hidden' ?> class="absolute -right-1.5 -top-1.5 min-w-[18px] rounded-full bg-[#C33939] px-1 text-center text-[10px] font-semibold leading-[18px] text-white"><?= (int) $app['badge'] > 99 ? '99+' : (int) $app['badge'] ?></span>
            <span class="sr-only"> unread</span>
        <?php elseif ($badge > 0): ?>
            <span
                class="absolute -top-1.5 -right-1.5 min-w-5 h-5 px-1.5 flex items-center justify-center rounded-full bg-[#C33939] text-[10px] font-bold leading-none text-white ring-2 ring-white tabular-nums"
                role="img"
                aria-label="<?= esc($app['badge_label'] ?? (string) $badge) ?>"
                title="<?= esc($app['badge_label'] ?? (string) $badge) ?>"
                data-app-badge><?= esc(\App\Services\Grades\GradesBadge::text($badge)) ?></span>
        <?php endif; ?>
    </span>

    <span class="text-[11px] leading-tight text-center <?= $active ? 'font-semibold text-brand' : 'text-gray-700' ?>">
        <?= esc($app['label']) ?>
    </span>

</<?= $tag ?>>
