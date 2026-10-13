<?php
/**
 * The system administrator's navigation, in four groups (SA14), under the
 * header on every admin page. The current page's link is marked.
 */
$menuPath = trim((string) service('request')->getUri()->getPath(), '/');

$menu = [
    'Customers'  => [['Institutions', 'admin/institutions'], ['Plans', 'admin/plans'], ['Users', 'admin/users']],
    'Platform'   => [['Feature flags', 'admin/flags'], ['Roles and permissions', 'admin/roles']],
    'Operations' => [['System status', 'admin/monitoring'], ['Backups', 'admin/backups'], ['Audit log', 'admin/audit-log'], ['Archive', 'admin/archive']],
    'Settings'   => [['General', 'admin/settings/general'], ['Security', 'admin/settings/security']],
];

$isCurrent = static fn (string $path): bool => preg_match('#(^|/)' . preg_quote($path, '#') . '(/|$)#', $menuPath) === 1;

$link    = 'rounded-full px-2.5 py-1 text-sm transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/50';
$current = 'bg-brand font-semibold text-white';
$idle    = 'text-brand hover:bg-brand-tint';
$home    = $isCurrent('admin/dashboard') || $menuPath === 'admin';
?>
<nav class="flex flex-wrap items-start gap-x-4 gap-y-3 border-b border-b-black/10 bg-white/75 px-4 py-2 backdrop-blur-xl sm:px-8 lg:px-12" aria-label="Administration" data-admin-menu>
    <div class="flex items-end self-stretch">
        <a class="<?= $link ?> <?= $home ? $current : $idle ?> font-semibold" href="<?= site_url('admin/dashboard') ?>" <?= $home ? 'aria-current="page"' : '' ?>>Home</a>
    </div>

    <?php foreach ($menu as $group => $links): ?>
        <div data-menu-group="<?= esc($group) ?>">
            <span class="block px-3 font-heading text-[11px] font-semibold uppercase tracking-wide text-[#646464]"><?= esc($group) ?></span>
            <ul class="flex flex-wrap gap-x-1">
                <?php foreach ($links as [$label, $path]): ?>
                    <li><a class="<?= $link ?> <?= $isCurrent($path) ? $current : $idle ?>" href="<?= site_url($path) ?>" <?= $isCurrent($path) ? 'aria-current="page"' : '' ?>><?= esc($label) ?></a></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endforeach; ?>
</nav>
