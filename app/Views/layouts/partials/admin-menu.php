<?php
/**
 * The system administrator menu, in four groups (SA14). The current page's
 * link is marked.
 */
$menuPath = trim((string) service('request')->getUri()->getPath(), '/');

$menu = [
    'Customers'  => [['Institutions', 'admin/institutions'], ['Plans', 'admin/plans'], ['Users', 'admin/users']],
    'Platform'   => [['Feature flags', 'admin/flags'], ['Roles and permissions', 'admin/roles']],
    'Operations' => [['System status', 'admin/monitoring'], ['Backups', 'admin/backups'], ['Audit log', 'admin/audit-log'], ['Archive', 'admin/archive']],
    'Settings'   => [['General', 'admin/settings/general'], ['Security', 'admin/settings/security']],
];

$isCurrent = static fn (string $path): bool => preg_match('#(^|/)' . preg_quote($path, '#') . '(/|$)#', $menuPath) === 1;
?>
<nav class="flex flex-wrap items-start gap-x-8 gap-y-2 border-b border-b-black/10 bg-white px-6 py-2 text-sm" aria-label="Administration" data-admin-menu>
    <a class="py-1 font-semibold <?= $isCurrent('admin/dashboard') || $menuPath === 'admin' ? 'text-gray-900' : 'text-[#0040A6] hover:underline' ?>" href="<?= site_url('admin/dashboard') ?>">Home</a>
    <?php foreach ($menu as $group => $links): ?>
        <div data-menu-group="<?= esc($group) ?>">
            <span class="block text-xs font-semibold uppercase tracking-wide text-gray-500"><?= esc($group) ?></span>
            <ul class="flex flex-wrap gap-x-4">
                <?php foreach ($links as [$label, $path]): ?>
                    <li><a class="<?= $isCurrent($path) ? 'font-semibold text-gray-900' : 'text-[#0040A6] hover:underline' ?>" href="<?= site_url($path) ?>" <?= $isCurrent($path) ? 'aria-current="page"' : '' ?>><?= esc($label) ?></a></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endforeach; ?>
</nav>
