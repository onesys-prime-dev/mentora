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

// A slim gutter beside the menu, so the page's panel and its tables have room.
$mainGutter = $mainGutter ?? 'px-4 sm:px-6 lg:px-6';
?>
<!DOCTYPE html>
<html lang="en" data-themeable>

<head>

    <?= $this->include('layouts/partials/head') ?>

</head>

<body
    class="admin-flat min-h-screen"
    data-page="<?= esc($page ?? 'admin') ?>"
    data-features="<?= esc(json_encode(current_features(), JSON_FORCE_OBJECT), 'attr') ?>"
>

    <?= $this->include('layouts/partials/page-progress') ?>

    <?php // From lg up the header and the menu stay put and only the page scrolls; below it the whole page scrolls as usual. ?>
    <div class="flex min-h-screen flex-col lg:h-screen lg:min-h-0 lg:overflow-hidden">

        <div class="relative z-30 shrink-0">

            <?= $this->include('layouts/partials/header') ?>

            <?= $this->include('layouts/partials/announcement') ?>

            <?= $this->include('layouts/partials/notifications-strip') ?>

        </div>

        <?php if ($flashToasts ?? false): ?>
            <?= $this->include('layouts/partials/flash-toasts') ?>
        <?php endif; ?>

        <div class="flex flex-1 flex-col lg:min-h-0 lg:flex-row">

            <?= $this->include('layouts/partials/admin-menu') ?>

            <main class="min-w-0 flex-1 <?= $mainGutter ?> py-8 focus:outline-none lg:overflow-y-auto [scrollbar-width:none] [&::-webkit-scrollbar]:hidden" tabindex="0">

                <?= $this->renderSection('content') ?>

            </main>

        </div>

    </div>

    <?php // Guarded actions ask for the password here, over the page, and carry on (shared/dialog.js). ?>
    <?php if ($reauthDialog ?? true): ?>
        <?= $this->include('layouts/partials/reauth-dialog') ?>
    <?php endif; ?>

    <?= $this->renderSection('scripts') ?>

</body>

</html>
