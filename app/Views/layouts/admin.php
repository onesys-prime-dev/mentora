<?php
/**
 * Shell for the system administrator area. The same shell as every other
 * area (layouts/app): the glass header with the bell, the Apps menu and the
 * account menu, the background, the banners and the toasts. What differs is
 * the Apps menu (the platform's tools, see global_apps('admin')) and a
 * grouped navigation bar under the header, which an administrator needs on
 * every page because the platform has more than a dozen of them.
 *
 * The header's page title is the system's name: the page's own <h1> says
 * which page it is.
 */
$this->setVar('user', $user ?? auth()->user());
$this->setVar('pageTitle', (string) (new App\Services\Admin\PlatformSettings())->get('systemName') . ' administration');

// The same default gutter as the app shell, a little wider to the right so tables have room.
$mainGutter = $mainGutter ?? 'px-4 sm:px-8 lg:px-12';
?>
<!DOCTYPE html>
<html lang="en" data-themeable>

<head>

    <?= $this->include('layouts/partials/head') ?>

</head>

<body
    class="min-h-screen bg-gray-100 bg-cover bg-center bg-no-repeat bg-fixed"
    style="--page-bg-light: url('<?= base_url('assets/images/background/light.png') ?>'); --page-bg-dark: url('<?= base_url('assets/images/background/dark.png') ?>'); background-image: var(--page-bg-light);"
    data-page="<?= esc($page ?? 'admin') ?>"
    data-features="<?= esc(json_encode(current_features(), JSON_FORCE_OBJECT), 'attr') ?>"
>

    <?= $this->include('layouts/partials/page-progress') ?>

    <div class="flex min-h-screen flex-col">

        <?= $this->include('layouts/partials/header') ?>

        <?= $this->include('layouts/partials/admin-menu') ?>

        <?= $this->include('layouts/partials/announcement') ?>

        <?= $this->include('layouts/partials/notifications-strip') ?>

        <?php if ($flashToasts ?? false): ?>
            <?= $this->include('layouts/partials/flash-toasts') ?>
        <?php endif; ?>

        <main class="flex-1 <?= $mainGutter ?> py-8">

            <?= $this->renderSection('content') ?>

        </main>

    </div>

    <?php // Guarded actions ask for the password here, over the page, and carry on (shared/dialog.js). ?>
    <?php if ($reauthDialog ?? true): ?>
        <?= $this->include('layouts/partials/reauth-dialog') ?>
    <?php endif; ?>

    <?= $this->renderSection('scripts') ?>

</body>

</html>
