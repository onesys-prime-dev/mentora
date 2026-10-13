<?php
/**
 * The tabs along the top of an institution's pages. Each tab is its own page.
 *
 * @var array<string, mixed> $tabInstitution the institution (id)
 * @var string               $tabActive      profile | subscription | users | flags | integrations | audit
 */
$tabBase = 'admin/institutions/' . $tabInstitution['id'];
$tabs    = [
    'profile'      => ['Profile', $tabBase],
    'subscription' => ['Subscription', $tabBase . '/subscription'],
    'users'        => ['Users', $tabBase . '/users'],
    'flags'        => ['Features', $tabBase . '/flags'],
    'integrations' => ['Integrations', $tabBase . '/integrations'],
    'audit'        => ['Audit', $tabBase . '/audit'],
];
?>
<nav class="mt-4 border-b border-gray-300" aria-label="Institution pages" data-institution-tabs>
    <ul class="-mb-px flex flex-wrap gap-1">
        <?php foreach ($tabs as $key => [$tabLabel, $tabPath]): ?>
            <li>
                <a
                    class="inline-block border-b-2 px-4 py-2 text-sm font-semibold <?= $key === $tabActive ? 'border-brand text-brand' : 'border-transparent text-[#646464] hover:border-gray-400 hover:text-gray-900' ?>"
                    href="<?= site_url($tabPath) ?>"
                    data-tab="<?= esc($key) ?>"
                    <?= $key === $tabActive ? 'aria-current="page"' : '' ?>
                ><?= esc($tabLabel) ?></a>
            </li>
        <?php endforeach; ?>
    </ul>
</nav>
