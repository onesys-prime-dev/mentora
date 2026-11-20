<?php
/**
 * The system administrator's navigation, in four groups (SA14), down the left
 * of every admin page. Each group folds to one line and only the one holding
 * the current page is open, so the bar stays short. The current page's link is
 * marked. Three tiers, as in partials/institution-menu: the full bar from xl;
 * at lg to xl a rail of Home and the four group icons that peeks open to the
 * full groups (app.css, shared/institution-menu.js); below lg the header's ☰
 * button opens the links as a full-screen tile menu (#nav-menu).
 */
$menuPath = trim((string) service('request')->getUri()->getPath(), '/');

$menu = [
    'Customers'  => ['hat', [['Institutions', 'admin/institutions'], ['Plans', 'admin/plans'], ['Users', 'admin/users']]],
    'Platform'   => ['modules', [['Feature flags', 'admin/flags'], ['Roles and permissions', 'admin/roles']]],
    'Operations' => ['tasks', [['System status', 'admin/monitoring'], ['Backups', 'admin/backups'], ['Audit log', 'admin/audit-log'], ['Archive', 'admin/archive']]],
    'Settings'   => ['settings', [['General', 'admin/settings/general'], ['Security', 'admin/settings/security']]],
];

$isCurrent = static fn (string $path): bool => preg_match('#(^|/)' . preg_quote($path, '#') . '(/|$)#', $menuPath) === 1;

$ring    = 'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/50';
$link    = 'block rounded-lg px-3 py-1.5 text-sm transition pointer-coarse:flex pointer-coarse:min-h-11 pointer-coarse:items-center ' . $ring;
$current = 'bg-brand font-semibold text-white';
$idle    = 'text-brand hover:bg-brand-tint';
$home    = $isCurrent('admin/dashboard') || $menuPath === 'admin';

// The phone and tablet menu: Home, then each group's links as tiles with the group's icon.
$tile = static function (string $label, string $icon, string $path, bool $on) use ($ring): string {
    return '<a href="' . site_url($path) . '" data-menu-tile'
        . ' class="flex min-h-24 flex-col items-center justify-center gap-2 rounded-2xl p-2 text-center text-xs font-medium transition pointer-coarse:min-w-11 ' . $ring . ' '
        . ($on ? 'bg-brand text-white' : 'bg-[#F6F8FC] text-gray-700 hover:bg-brand-tint hover:text-brand') . '"'
        . ($on ? ' aria-current="page"' : '') . '>'
        . icon($icon, 'h-6 w-6 shrink-0 text-current')
        . '<span>' . esc($label) . '</span></a>';
};

$tiles = '<div class="grid grid-cols-3 gap-2">' . $tile('Home', 'home', 'admin/dashboard', $home) . '</div>';

foreach ($menu as $group => [$groupIcon, $links]) {
    $tiles .= '<section class="flex flex-col gap-2"><h3 class="text-[11px] font-semibold text-gray-500">' . esc($group) . '</h3><div class="grid grid-cols-3 gap-2">';

    foreach ($links as [$label, $path]) {
        $tiles .= $tile($label, $groupIcon, $path, $isCurrent($path));
    }

    $tiles .= '</div></section>';
}
?>
<nav class="max-lg:hidden lg:shrink-0 lg:max-xl:sticky lg:max-xl:top-[var(--app-header-h,4.75rem)] lg:max-xl:z-[25] lg:max-xl:h-[calc(100dvh-var(--app-header-h,4.75rem))] lg:max-xl:w-[4.5rem] xl:sticky xl:top-[var(--app-header-h,4.75rem)] xl:h-[calc(100dvh-var(--app-header-h,4.75rem))] xl:w-64 xl:overflow-y-auto [scrollbar-width:none] [&::-webkit-scrollbar]:hidden" aria-label="Administration" data-admin-menu>

    <div data-side-menu-panel class="flex min-h-full flex-col gap-1 border-r border-r-black/10 bg-white px-3 py-5 xl:px-4 [scrollbar-width:none] [&::-webkit-scrollbar]:hidden">

        <a class="<?= $link ?> flex items-center gap-3 font-semibold rail:justify-center rail:px-0 rail:py-2.5 <?= $home ? $current : $idle ?>" href="<?= site_url('admin/dashboard') ?>" title="Home" <?= $home ? 'aria-current="page"' : '' ?>>
            <?= icon('home', 'h-5 w-5 shrink-0 text-current') ?><span class="rail:sr-only">Home</span>
        </a>

        <?php foreach ($menu as $group => [$groupIcon, $links]): ?>
            <?php $groupOpen = array_filter($links, static fn (array $item): bool => $isCurrent($item[1])) !== []; ?>
            <details class="group mt-1" data-menu-group="<?= esc($group) ?>" <?= $groupOpen ? 'open' : '' ?>>
                <summary title="<?= esc($group, 'attr') ?>" class="flex cursor-pointer list-none items-center justify-between rounded-lg px-3 py-2 pointer-coarse:min-h-11 text-sm font-semibold text-[#1A1C1E] transition hover:bg-brand-tint rail:justify-center rail:px-0 rail:py-2.5 [&::-webkit-details-marker]:hidden <?= $ring ?> <?= $groupOpen ? 'rail:bg-brand/10' : '' ?>">
                    <span class="flex items-center gap-3"><?= icon($groupIcon, 'h-5 w-5 shrink-0 text-brand') ?><span class="rail:sr-only"><?= esc($group) ?></span></span>
                    <svg class="h-4 w-4 shrink-0 text-[#646464] transition group-open:rotate-180 rail:hidden" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m5 8 5 5 5-5"/></svg>
                </summary>
                <ul class="ml-5 mt-1 flex flex-col gap-0.5 border-l-2 border-gray-200 pl-3 rail:hidden">
                    <?php foreach ($links as [$label, $path]): ?>
                        <li><a class="<?= $link ?> <?= $isCurrent($path) ? $current : $idle ?>" href="<?= site_url($path) ?>" <?= $isCurrent($path) ? 'aria-current="page"' : '' ?>><?= esc($label) ?></a></li>
                    <?php endforeach; ?>
                </ul>
            </details>
        <?php endforeach; ?>

        <?php // lg to xl only: peeks the rail open, which a touch screen cannot do by hover (shared/institution-menu.js). This bar has no collapse at xl, so the button is hidden there. ?>
        <button
            type="button"
            data-menu-collapse
            aria-pressed="true"
            aria-label="Expand the menu"
            title="Expand the menu"
            class="mt-auto flex items-center gap-3 rounded-lg px-3 py-2 pointer-coarse:min-h-11 text-sm font-semibold text-[#1A1C1E] transition hover:bg-brand-tint rail:justify-center rail:px-0 rail:py-2.5 xl:hidden <?= $ring ?>">
            <svg class="h-5 w-5 shrink-0 text-brand transition rail:rotate-180" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M15 6l-6 6 6 6" /></svg>
            <span class="rail:sr-only">Collapse</span>
        </button>

    </div>
</nav>

<?= view('components/dialog', ['id' => 'nav-menu', 'variant' => 'menu', 'title' => 'System administrator', 'body' => $tiles, 'size' => 'md', 'footer' => '', 'autoOpen' => false, 'centered' => false, 'topAction' => null], ['saveData' => false]) ?>
