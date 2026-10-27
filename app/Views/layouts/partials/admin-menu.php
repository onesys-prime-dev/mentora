<?php
/**
 * The system administrator's navigation, in four groups (SA14), down the left
 * of every admin page. Each group folds to one line and only the one holding
 * the current page is open, so the bar stays short. The current page's link is
 * marked. Below the lg breakpoint the bar hides behind a Menu button (a tick
 * box, so it needs no script).
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
$link    = 'block rounded-lg px-3 py-1.5 text-sm transition ' . $ring;
$current = 'bg-brand font-semibold text-white';
$idle    = 'text-brand hover:bg-brand-tint';
$home    = $isCurrent('admin/dashboard') || $menuPath === 'admin';
?>
<nav class="border-b border-b-black/10 bg-white lg:w-64 lg:shrink-0 lg:overflow-y-auto lg:border-b-0 lg:border-r lg:border-r-black/10" aria-label="Administration" data-admin-menu>

    <input type="checkbox" id="admin-menu-toggle" class="peer sr-only">
    <label for="admin-menu-toggle" class="flex cursor-pointer items-center justify-between px-4 py-3 text-sm font-semibold text-brand peer-focus-visible:ring-2 peer-focus-visible:ring-inset peer-focus-visible:ring-brand/50 sm:px-8 lg:hidden">
        Menu
        <?= icon('settings', 'h-5 w-5 text-current') ?>
    </label>

    <div class="hidden flex-col gap-1 px-3 pb-4 peer-checked:flex lg:flex lg:px-4 lg:py-5">

        <a class="<?= $link ?> flex items-center gap-3 font-semibold <?= $home ? $current : $idle ?>" href="<?= site_url('admin/dashboard') ?>" <?= $home ? 'aria-current="page"' : '' ?>>
            <?= icon('home', 'h-5 w-5 shrink-0 text-current') ?>Home
        </a>

        <?php foreach ($menu as $group => [$groupIcon, $links]): ?>
            <?php $groupOpen = array_filter($links, static fn (array $item): bool => $isCurrent($item[1])) !== []; ?>
            <details class="group mt-1" data-menu-group="<?= esc($group) ?>" <?= $groupOpen ? 'open' : '' ?>>
                <summary class="flex cursor-pointer list-none items-center justify-between rounded-lg px-3 py-2 text-sm font-semibold text-[#1A1C1E] transition hover:bg-brand-tint [&::-webkit-details-marker]:hidden <?= $ring ?>">
                    <span class="flex items-center gap-3"><?= icon($groupIcon, 'h-5 w-5 shrink-0 text-brand') ?><?= esc($group) ?></span>
                    <svg class="h-4 w-4 shrink-0 text-[#646464] transition group-open:rotate-180" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m5 8 5 5 5-5"/></svg>
                </summary>
                <ul class="ml-5 mt-1 flex flex-col gap-0.5 border-l-2 border-gray-200 pl-3">
                    <?php foreach ($links as [$label, $path]): ?>
                        <li><a class="<?= $link ?> <?= $isCurrent($path) ? $current : $idle ?>" href="<?= site_url($path) ?>" <?= $isCurrent($path) ? 'aria-current="page"' : '' ?>><?= esc($label) ?></a></li>
                    <?php endforeach; ?>
                </ul>
            </details>
        <?php endforeach; ?>

    </div>
</nav>
