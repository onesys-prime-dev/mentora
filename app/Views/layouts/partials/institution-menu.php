<?php
/**
 * The institutional administrator's navigation, down the left of every page in
 * their area. It draws from global_apps('institutional_admin'), the same list the
 * dashboard's Quick Access uses, and only groups it. Collapsed it is an icon rail;
 * the choice is remembered in the browser (shared/institution-menu.js). Below the lg
 * breakpoint the bar hides behind a Menu button (a tick box, so it needs no script).
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
    'Academics'   => ['academics', 'courses', 'terms', 'offerings'],
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
?>
<nav
    class="group/nav border-b border-b-black/10 bg-white/90 lg:min-h-0 lg:w-64 lg:shrink-0 lg:overflow-y-auto lg:border-b-0 lg:border-r lg:border-r-black/10 lg:data-[collapsed=true]:w-[4.5rem] [scrollbar-width:none] [&::-webkit-scrollbar]:hidden"
    aria-label="Institution"
    data-institution-menu>

    <input type="checkbox" id="institution-menu-toggle" class="peer sr-only" aria-label="Show the menu">
    <label for="institution-menu-toggle" class="flex cursor-pointer items-center justify-between px-4 py-3 text-sm font-semibold text-brand peer-focus-visible:ring-2 peer-focus-visible:ring-inset peer-focus-visible:ring-brand/50 sm:px-8 lg:hidden">
        Menu
        <?= icon('apps', 'h-5 w-5 text-current') ?>
    </label>

    <div class="hidden flex-col gap-1 px-3 pb-4 peer-checked:flex lg:flex lg:min-h-full lg:px-3 lg:py-5">

        <?php foreach ($groups as $label => $keys): ?>

            <?php $items = array_values(array_filter(array_map(static fn (string $key) => $apps[$key] ?? null, $keys))); ?>

            <?php if ($items === []): ?>
                <?php continue; ?>
            <?php endif; ?>

            <?php if ($label !== ''): ?>
                <p class="mx-3 mb-1 mt-4 text-[11px] font-semibold uppercase tracking-wider text-gray-500 group-data-[collapsed=true]/nav:hidden"><?= esc($label) ?></p>
                <div class="mx-auto my-2 hidden h-px w-6 bg-black/10 group-data-[collapsed=true]/nav:block" aria-hidden="true"></div>
            <?php endif; ?>

            <?php foreach ($items as $app): ?>

                <?php $current = $isCurrent($app); ?>

                <a
                    href="<?= esc($app['url'], 'attr') ?>"
                    title="<?= esc($app['label'], 'attr') ?>"
                    data-app-tile="<?= esc($app['key'], 'attr') ?>"
                    <?= $current ? 'aria-current="page"' : '' ?>
                    class="flex items-center gap-3 rounded-xl px-3 py-2.5 text-sm font-medium transition group-data-[collapsed=true]/nav:justify-center group-data-[collapsed=true]/nav:px-0 <?= $ring ?> <?= $current ? 'bg-brand/10 text-brand' : 'text-gray-700 hover:bg-brand-tint hover:text-brand' ?>">
                    <?= icon($app['icon'], 'h-5 w-5 shrink-0 text-current') ?>
                    <span class="truncate group-data-[collapsed=true]/nav:sr-only"><?= esc($app['label']) ?></span>
                </a>

            <?php endforeach; ?>

        <?php endforeach; ?>

        <button
            type="button"
            data-menu-collapse
            aria-pressed="false"
            aria-label="Collapse the menu"
            title="Collapse the menu"
            class="mt-auto hidden items-center gap-3 rounded-xl px-3 py-2.5 text-sm font-medium text-gray-700 transition hover:bg-brand-tint hover:text-brand group-data-[collapsed=true]/nav:justify-center group-data-[collapsed=true]/nav:px-0 lg:flex <?= $ring ?>">
            <svg class="h-5 w-5 shrink-0 transition group-data-[collapsed=true]/nav:rotate-180" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M15 6l-6 6 6 6" /></svg>
            <span class="group-data-[collapsed=true]/nav:sr-only">Collapse</span>
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
