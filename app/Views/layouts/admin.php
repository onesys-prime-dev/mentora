<?php
/**
 * Shell for the system administrator area. The same shell as every other
 * area (layouts/app): the glass header with the bell, the Apps menu and the
 * account menu, the background, the banners and the toasts. What differs is
 * the Apps menu (the platform's tools, see global_apps('admin')) and a
 * grouped sidebar down the left, which an administrator needs on
 * every page because the platform has more than a dozen of them.
 *
 * The header's page title is the system's name: the page's own <h1> says
 * which page it is.
 */
$this->setVar('user', $user ?? auth()->user());
$this->setVar('pageTitle', (string) (new App\Services\Admin\PlatformSettings())->get('systemName') . ' administration');
// The header's ☰ button opens the menu's phone and tablet dialog (partials/admin-menu).
$this->setVar('navMenuId', 'nav-menu');

// A slim gutter beside the menu, so the page's panel and its tables have room.
$mainGutter = $mainGutter ?? 'px-gutter';

// The admin pages sit on a flat grey. A page every role shares (Notifications) passes 'flat' => false to
// keep the same background as everyone else's copy of it.
$flat = $flat ?? true;
?>
<!DOCTYPE html>
<html lang="en" data-themeable>

<head>

    <?= $this->include('layouts/partials/head') ?>

</head>

<body
    <?php if ($flat): ?>
    class="admin-flat min-h-dvh"
    <?php else: ?>
    class="min-h-dvh bg-gray-100 bg-cover bg-center bg-no-repeat bg-fixed"
    style="--page-bg-light: url('<?= base_url('assets/images/background/light.png') ?>'); --page-bg-dark: url('<?= base_url('assets/images/background/dark.png') ?>'); background-image: var(--page-bg-light);"
    <?php endif; ?>
    data-page="<?= esc($page ?? 'admin') ?>"
    data-features="<?= esc(json_encode(current_features(), JSON_FORCE_OBJECT), 'attr') ?>"
>

    <?= $this->include('layouts/partials/page-progress') ?>

    <?php // When the shell locks (wide and tall) the header and the menu stay put and only the page scrolls; otherwise the whole page scrolls as usual. ?>
    <div class="flex min-h-dvh flex-col pl-safe pr-safe lock:h-dvh lock:min-h-0 lock:overflow-hidden">

        <div class="sticky top-0 z-30 shrink-0 pt-safe" data-app-header>

            <?= $this->include('layouts/partials/header') ?>

            <?= $this->include('layouts/partials/announcement') ?>

                    </div>

        <?php if ($flashToasts ?? false): ?>
            <?= $this->include('layouts/partials/flash-toasts') ?>
        <?php endif; ?>

        <div class="flex flex-1 flex-col lock:min-h-0 lg:flex-row">

            <?= $this->include('layouts/partials/admin-menu') ?>

            <main data-pinned-panel class="min-w-0 flex-1 <?= $mainGutter ?> pb-8 pt-3 focus:outline-none lock:overflow-y-auto [scrollbar-width:none] [&::-webkit-scrollbar]:hidden" tabindex="0">

                <div>

                    <?= $this->renderSection('content') ?>

                </div>

            </main>

        </div>

    </div>

    <?php // Guarded actions ask for the password here, over the page, and carry on (shared/dialog.js). ?>
    <?php if ($reauthDialog ?? true): ?>
        <?= $this->include('layouts/partials/reauth-dialog') ?>
    <?php endif; ?>

    <?= $this->include('layouts/partials/header-metrics') ?>

    <?= $this->include('layouts/partials/person-dialog') ?>

    <?= $this->renderSection('scripts') ?>

</body>

</html>
