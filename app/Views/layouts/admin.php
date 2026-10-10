<?php
/**
 * Shell for the system administrator area. Separate from layouts/app because
 * the app header lists student/instructor apps (courses, calendar, ...) that
 * do not exist here.
 */
$email = $user->email ?? '';
?>
<!DOCTYPE html>
<html lang="en">

<head>

    <?= $this->include('layouts/partials/head') ?>

</head>

<body class="min-h-screen bg-gray-100" data-page="<?= esc($page ?? 'admin') ?>">

    <div class="flex min-h-screen flex-col">

        <header class="flex items-center justify-between border-b border-b-black/10 bg-white px-6 py-3 shadow">
            <a class="flex items-center gap-3" href="<?= site_url('admin/dashboard') ?>">
                <img class="h-11 w-auto" src="<?= base_url('assets/images/logos/Mentora_logo_type.png') ?>" alt="Mentora">
                <span class="text-xl font-semibold text-[#0040A6]"><?= esc((new App\Services\Admin\PlatformSettings())->get('systemName')) ?> administration</span>
            </a>

            <div class="flex items-center gap-4 text-sm text-gray-700">
                <a class="font-semibold text-[#0040A6] hover:underline" href="<?= site_url('notifications') ?>">Notifications<?php $unreadNotes = (new App\Services\Notifications\NotificationService())->unreadCount((int) auth()->id()); ?><?php if ($unreadNotes > 0): ?> <span class="rounded-full bg-red-600 px-2 py-0.5 text-xs font-bold text-white" data-unread-count><?= esc((string) $unreadNotes) ?></span><?php endif; ?></a>
                <span><?= esc($email) ?></span>
                <a class="font-semibold text-[#0040A6] hover:underline" href="<?= site_url('/logout') ?>">Log out</a>
            </div>
        </header>

        <?= $this->include('layouts/partials/announcement') ?>

        <main class="flex-1 px-6 py-8 lg:px-12">

            <?= $this->renderSection('content') ?>

        </main>

    </div>

    <?= $this->renderSection('scripts') ?>

</body>

</html>
