<?php
/**
 * The institutional administrator's navigation, down the left of every page in
 * their area. It draws from global_apps('institutional_admin'), the same list the
 * dashboard's Quick Access uses, and only groups it. Three tiers:
 *   - xl up: the full sidebar; collapsed it is an icon rail, and the choice is
 *     remembered in the browser (shared/institution-menu.js).
 *   - lg to xl: always the icon rail, sticky under the header; hover, focus or the
 *     collapse button peek it open over the page (app.css, the rail: variant).
 *   - below lg: hidden; the header's ☰ button opens the same groups as a
 *     full-screen tile menu (#nav-menu, components/dialog's menu variant).
 *
 * @var string|null $page the page you are on (matches an app's key)
 */
$menuPath = trim((string) service('request')->getUri()->getPath(), '/');
$menuPath = preg_replace('#^index\.php/?#', '', $menuPath);

$apps = [];

foreach (global_apps('institutional_admin') as $app) {
    if ($app['url'] !== null) {
        // Home is the house here, as in the system administrator's menu; the Apps grid stays Quick Access's.
        $apps[$app['key']] = $app['key'] === 'dashboard' ? ['icon' => 'home'] + $app : $app;
    }
}

$groups = [
    ''            => ['dashboard'],
    'Academics'   => ['academics', 'courses', 'terms', 'offerings', 'sections'],
    'Community'   => ['people'],
    'Insights'    => ['reports', 'audit'],
    'Institution' => ['settings', 'subscription', 'integrations', 'institution'],
];

$isCurrent = static function (array $app) use ($menuPath, $page): bool {
    if (($page ?? null) === $app['key']) {
        return true;
    }

    $path = trim((string) parse_url((string) $app['url'], PHP_URL_PATH), '/');
    $path = preg_replace('#^.*?index\.php/?#', '', $path);

    return $path !== '' && preg_match('#(^|/)' . preg_quote($path, '#') . '(/|$)#', $menuPath) === 1;
};

$ring = 'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/50';

$menuGroups = [];

foreach ($groups as $label => $keys) {
    $items = array_values(array_filter(array_map(static fn (string $key) => $apps[$key] ?? null, $keys)));

    if ($items !== []) {
        $menuGroups[$label] = $items;
    }
}

// The phone and tablet menu: the same groups as tiles.
$tiles = '';

foreach ($menuGroups as $label => $items) {
    $tiles .= '<section class="flex flex-col gap-2">';
    $tiles .= $label !== '' ? '<h3 class="text-[11px] font-semibold text-gray-500">' . esc($label) . '</h3>' : '';
    $tiles .= '<div class="grid grid-cols-3 gap-2">';

    foreach ($items as $app) {
        $current = $isCurrent($app);
        $tiles .= '<a href="' . esc($app['url'], 'attr') . '" data-menu-tile'
            . ' class="flex min-h-24 flex-col items-center justify-center gap-2 rounded-2xl p-2 text-center text-xs font-medium transition pointer-coarse:min-w-11 ' . $ring . ' '
            . ($current ? 'bg-brand text-white' : 'bg-[#F6F8FC] text-gray-700 hover:bg-brand-tint hover:text-brand') . '"'
            . ($current ? ' aria-current="page"' : '') . '>'
            . icon($app['icon'], 'h-6 w-6 shrink-0 text-current')
            . '<span>' . esc($app['label']) . '</span></a>';
    }

    $tiles .= '</div></section>';
}
?>
<nav
    class="max-lg:hidden lg:shrink-0 lg:max-xl:sticky lg:max-xl:top-[var(--app-header-h,4.75rem)] lg:max-xl:z-[25] lg:max-xl:h-[calc(100dvh-var(--app-header-h,4.75rem))] lg:max-xl:w-[4.5rem] xl:min-h-0 xl:sticky xl:top-[var(--app-header-h,4.75rem)] xl:h-[calc(100dvh-var(--app-header-h,4.75rem))] xl:w-64 xl:overflow-y-auto xl:data-[collapsed=true]:w-[4.5rem] [scrollbar-width:none] [&::-webkit-scrollbar]:hidden"
    aria-label="Institution"
    data-institution-menu>

    <div data-side-menu-panel class="flex min-h-full flex-col gap-1 border-r border-r-black/10 bg-white xl:bg-white/90 px-3 py-5 [scrollbar-width:none] [&::-webkit-scrollbar]:hidden">

        <?php foreach ($menuGroups as $label => $items): ?>

            <?php if ($label !== ''): ?>
                <p class="mx-3 mb-1 mt-4 text-[11px] font-semibold uppercase tracking-wider text-gray-500 rail:hidden"><?= esc($label) ?></p>
                <div class="mx-auto my-2 hidden h-px w-6 bg-black/10 rail:block" aria-hidden="true"></div>
            <?php endif; ?>

            <?php foreach ($items as $app): ?>

                <?php $current = $isCurrent($app); ?>

                <a
                    href="<?= esc($app['url'], 'attr') ?>"
                    title="<?= esc($app['label'], 'attr') ?>"
                    data-app-tile="<?= esc($app['key'], 'attr') ?>"
                    <?= $current ? 'aria-current="page"' : '' ?>
                    class="flex items-center gap-3 rounded-xl px-3 py-2.5 pointer-coarse:min-h-11 text-sm font-medium transition rail:justify-center rail:px-0 <?= $ring ?> <?= $current ? 'bg-brand/10 text-brand' : 'text-gray-700 hover:bg-brand-tint hover:text-brand' ?>">
                    <?= icon($app['icon'], 'h-5 w-5 shrink-0 text-current') ?>
                    <span class="truncate rail:sr-only"><?= esc($app['label']) ?></span>
                </a>

            <?php endforeach; ?>

        <?php endforeach; ?>

        <?php // At xl it collapses the menu; at lg to xl it peeks the rail open (shared/institution-menu.js). ?>
        <button
            type="button"
            data-menu-collapse
            aria-pressed="false"
            aria-label="Collapse the menu"
            title="Collapse the menu"
            class="mt-auto flex items-center gap-3 rounded-xl px-3 py-2.5 pointer-coarse:min-h-11 text-sm font-medium text-gray-700 transition hover:bg-brand-tint hover:text-brand rail:justify-center rail:px-0 <?= $ring ?>">
            <svg class="h-5 w-5 shrink-0 transition rail:rotate-180" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M15 6l-6 6 6 6" /></svg>
            <span class="rail:sr-only">Collapse</span>
        </button>

    </div>

    <script>
        (function () {
            try {
                if (localStorage.getItem('mentora-institution-menu') === 'collapsed') {
                    document.currentScript.parentElement.setAttribute('data-collapsed', 'true');
                }
            } catch (e) { /* storage blocked: expanded */ }
        })();
    </script>
</nav>

<?= view('components/dialog', ['id' => 'nav-menu', 'variant' => 'menu', 'title' => 'Institution admin', 'body' => $tiles, 'size' => 'md', 'footer' => '', 'autoOpen' => false, 'centered' => false, 'topAction' => null], ['saveData' => false]) ?>
