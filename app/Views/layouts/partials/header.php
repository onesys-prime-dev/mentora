<?php
$userRoles = array_values(array_filter($user->getGroups(), 'is_string'));
$activeRole = active_role();
$userRole = $activeRole !== '' ? $activeRole : ($userRoles[0] ?? '');
$userRole = esc($userRole);
$canSwitchRole = count($userRoles) > 1;

/*
| Account menu identity. A student is identified by number and program; an
| instructor has no number, so the role chip carries it. No avatar column
| yet — everyone gets the placeholder photo (same as the dashboard card).
*/
$profile = current_user_profile($user);

$fullName = trim(($profile['first_name'] ?? '') . ' ' . ($profile['last_name'] ?? ''));

$email = $profile['email'] ?? ($user->email ?? '');

$displayName = $fullName !== '' ? $fullName : $email;

$studentMeta = $userRole === 'student'
    ? implode(' · ', array_filter([$profile['student_number'] ?? null, $profile['program_code'] ?? null]))
    : '';

$avatarUrl = base_url('assets/images/placeholder/profile.png');

// The Apps menu and the phone block draw the same tiles; work them (and their badges) out once.
$headerApps = $userRole === 'institutional_admin' ? [] : global_apps($userRole);

// Not built yet — shown so the menu's shape is settled, but inert. "My Profile" is real for the
// roles that have a profile page (a student's, to read; a parent's, with their email choices).
$accountLinks = [
    ['label' => 'My Profile', 'icon' => 'user', 'url' => in_array($userRole, ['student', 'parent'], true) ? site_url($userRole . '/profile') : null],
    ['label' => 'Settings', 'icon' => 'settings'],
    ['label' => 'Help & Support', 'icon' => 'help'],
];
?>
<header
    class="
        flex
        items-center
        justify-between
        border-b
        border-b-black/10
        bg-white
        gap-2
        px-3
        py-2
        sm:px-6
        sm:py-3
        shadow
    ">
    <div class="flex min-w-0 gap-2 items-stretch font-lao">
        <?php // Below lg the side menu (the layout says which) lives behind this button, as a full-screen dialog. ?>
        <?php if (($navMenuId ?? null) !== null): ?>
            <button
                type="button"
                data-dialog-open="<?= esc($navMenuId, 'attr') ?>"
                aria-haspopup="dialog" aria-controls="<?= esc($navMenuId, 'attr') ?>"
                aria-label="Open the menu"
                class="lg:hidden h-11 w-11 shrink-0 self-center flex justify-center items-center rounded-full cursor-pointer transition duration-150 text-brand hover:bg-brand-tint hover:text-brand-dark active:scale-95 motion-reduce:transition-none motion-reduce:active:scale-100 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/55">
                <svg class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M4 7h16M4 12h16M4 17h16" /></svg>
            </button>
        <?php endif; ?>
        <a
            class="flex gap-1 items-center self-center"
            href="<?= site_url(role_home($userRole) . '/dashboard') ?>"
            data-brand-logo>
            <?php // Phones get the mark alone; the wordmark needs the width the title wants. The span makes the 32px mark a 44px touch target. ?>
            <span class="flex items-center justify-center sm:hidden pointer-coarse:min-h-11 pointer-coarse:min-w-11">
                <img
                    class="h-8 w-auto"
                    src="<?= base_url('assets/images/logos/logo.png') ?>" alt="Mentora">
            </span>
            <img
                class="h-11 w-auto max-sm:hidden"
                src="<?= base_url('assets/images/logos/Mentora_logo_type.png') ?>" alt="Mentora">
        </a>

        <?php $headerBranding = (new \App\Services\Institutions\InstitutionBranding())->forUser(auth()->user()); ?>
        <?php if ($headerBranding !== null && $headerBranding['logoUrl'] !== null): ?>
            <span class="mx-1 h-8 w-px self-center bg-black/10 max-sm:hidden" aria-hidden="true"></span>
            <img class="h-9 w-auto max-sm:hidden max-w-[10rem] self-center object-contain" src="<?= esc($headerBranding['logoUrl']) ?>" alt="<?= esc($headerBranding['name']) ?>" data-institution-logo>
        <?php endif; ?>

        <div class="flex min-w-0 items-center sm:items-end">
            <?php /* The page's own <h1> says where you are; the layout sets headerTitle false then. */ ?>
            <?php if ($page !== null && $page !== 'dashboard' && ($headerTitle ?? true)): ?>
                <span class="min-w-0 truncate text-base sm:text-2xl font-semibold text-brand capitalize pb-px" data-header-title>
                    <?= esc($pageTitle ?? ucwords(str_replace(['-', '_'], ' ', (string) $page))) ?>
                </span>
            <?php endif; ?>
        </div>
    </div>

    <div class="flex shrink-0 gap-2 sm:gap-4 items-center">
        <?php // One pill for the quick-access icons: Messages, notifications, dark mode, Apps. ?>
        <div class="flex items-center gap-0.5 rounded-full bg-white p-1 shadow" data-header-pill>
        <?php // Messages is quick access: next to the bell and dark mode, not inside the Apps menu. Null while the flag is off or the role has no messages.use. ?>
        <?php $messagesTile = messages_tile($userRole); ?>
        <?php if ($messagesTile !== null): ?>
        <a
            href="<?= esc($messagesTile['url']) ?>"
            aria-label="Messages"
            title="Messages"
            <?= ($page ?? null) === 'messages' ? 'aria-current="page"' : '' ?>
            class="relative h-11 w-11 flex justify-center items-center rounded-full cursor-pointer transition duration-150 text-brand hover:bg-brand-tint hover:text-brand-dark active:scale-95 motion-reduce:transition-none motion-reduce:active:scale-100 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/55 aria-[current=page]:bg-brand aria-[current=page]:text-white max-sm:hidden">
            <?= icon('chat', 'h-5 w-5 text-current') ?>
            <span data-messages-badge data-url="<?= esc(site_url('messages/api/unread-count')) ?>" <?= (int) $messagesTile['badge'] > 0 ? '' : 'hidden' ?> class="absolute -right-1.5 -top-1.5 min-w-[18px] rounded-full bg-[#C33939] px-1 text-center text-[10px] font-semibold leading-[18px] text-white"><?= (int) $messagesTile['badge'] > 99 ? '99+' : (int) $messagesTile['badge'] ?></span>
        </a>
        <span class="h-5 w-px bg-black/10 max-sm:hidden" aria-hidden="true"></span>
        <?php endif; ?>
        <div class="relative">
            <button
                id="notifications-button"
                type="button"
                aria-label="Notifications"
                <?= ($page ?? null) === 'notifications' ? 'aria-current="page"' : '' ?>
                aria-haspopup="true"
                aria-expanded="false"
                aria-controls="notifications-panel"
                class="relative h-11 w-11 flex justify-center items-center rounded-full cursor-pointer transition duration-150 text-brand hover:bg-brand-tint hover:text-brand-dark active:scale-95 motion-reduce:transition-none motion-reduce:active:scale-100 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/55 aria-expanded:bg-brand aria-expanded:text-white aria-expanded:hover:text-white aria-[current=page]:bg-brand aria-[current=page]:text-white aria-[current=page]:hover:bg-brand aria-[current=page]:hover:text-white">
                <?= icon('bell', 'h-5 w-5 text-current') ?>
                <?php $bellCount = (new \App\Services\Notifications\NotificationService())->unreadCount((int) $user->id); ?>
                <span data-notifications-badge data-count="<?= $bellCount ?>" <?= $bellCount > 0 ? '' : 'hidden' ?> class="absolute -right-1.5 -top-1.5 min-w-[18px] rounded-full bg-[#C33939] px-1 text-center text-[10px] font-semibold leading-[18px] text-white"><?= $bellCount > 99 ? '99+' : $bellCount ?></span>
            </button>

            <!-- Latest notifications, 7 at a time; more load as the list is scrolled -->
            <div
                id="notifications-panel"
                data-header-panel
                data-feed-url="<?= site_url('notifications/feed') ?>"
                class="hidden z-50 absolute right-0 top-full mt-2 w-96 max-w-[calc(100vw-1.5rem)] flex flex-col overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-lg max-sm:fixed max-sm:inset-x-2.5 max-sm:top-[calc(var(--app-header-h,4.75rem)+0.5rem)] max-sm:mt-0 max-sm:w-auto max-sm:max-h-[60dvh] max-sm:overflow-y-auto">

                <div class="flex items-center justify-between px-5 pt-4 pb-2">
                    <span class="text-lg font-display">Notifications</span>
                    <button type="button" data-notifications-mark-all hidden class="text-[13px] font-semibold text-brand hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/55">Mark all read</button>
                </div>

                <div class="flex gap-1.5 px-5 pb-2" role="group" aria-label="Filter notifications">
                    <button type="button" data-notifications-filter="all" aria-pressed="true" class="rounded-full border border-brand bg-brand px-3.5 py-1 text-[13px] font-semibold text-white focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/55">All</button>
                    <button type="button" data-notifications-filter="unread" aria-pressed="false" class="rounded-full border border-transparent bg-[#eef2f8] px-3.5 py-1 text-[13px] text-gray-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/55">Unread</button>
                </div>

                <div class="max-h-[min(24rem,60vh)] overflow-y-auto overscroll-contain [scrollbar-width:none] [&::-webkit-scrollbar]:hidden" data-notifications-scroll>
                    <ul class="flex flex-col divide-y divide-gray-100" data-notifications-list></ul>
                    <p class="hidden px-5 py-8 text-center text-sm text-[#767676]" data-notifications-empty>You have no notifications yet.</p>
                    <p class="hidden px-5 py-6 text-center text-sm text-[#C33939]" data-notifications-error>
                        Could not load notifications.
                        <button type="button" class="font-semibold underline" data-notifications-retry>Try again</button>
                    </p>
                </div>

                <a
                    href="<?= site_url('notifications') ?>"
                    class="block border-t border-gray-100 px-5 py-3 text-center text-sm font-semibold text-brand transition hover:bg-brand-tint focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-brand/55">
                    View all notifications
                </a>

            </div>
        </div>
        <span class="h-5 w-px bg-black/10 max-sm:hidden" aria-hidden="true"></span>
        <button
            type="button"
            data-theme-toggle aria-pressed="false" aria-label="Dark mode is off. Switch to dark mode" title="Switch to dark mode"
            class="max-sm:hidden h-11 w-11 flex justify-center items-center rounded-full cursor-pointer transition duration-150 text-brand hover:bg-brand-tint hover:text-brand-dark active:scale-95 motion-reduce:transition-none motion-reduce:active:scale-100 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/55">
            <?= icon('night', 'h-5 w-5 text-current') ?>
        </button>

        <?php // The institutional administrator has a sidebar instead of the Apps menu. ?>
        <?php if ($userRole !== 'institutional_admin'): ?>
        <span class="h-5 w-px bg-black/10 max-sm:hidden" aria-hidden="true"></span>
        <div class="relative">
            <button
                id="app-menu-button"
                type="button"
                aria-label="Apps"
                aria-haspopup="menu"
                aria-expanded="false"
                aria-controls="app-menu-panel"
                class="max-sm:hidden h-11 w-11 flex justify-center items-center rounded-full cursor-pointer transition duration-150 text-brand hover:bg-brand-tint hover:text-brand-dark active:scale-95 motion-reduce:transition-none motion-reduce:active:scale-100 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/55 aria-expanded:bg-brand aria-expanded:text-white aria-expanded:hover:text-white">
                <?= icon('apps', 'h-5 w-5 text-current') ?>
            </button>

            <!-- Global apps — same list as the dashboard's Quick Access (global_apps()) -->
            <div
                id="app-menu-panel"
                data-header-panel
                class="hidden z-50 absolute right-0 top-full mt-2 w-72 flex flex-col gap-4 rounded-2xl border border-gray-200 bg-white p-5 shadow-lg max-sm:fixed max-sm:inset-x-2.5 max-sm:top-[calc(var(--app-header-h,4.75rem)+0.5rem)] max-sm:mt-0 max-sm:w-auto max-sm:max-h-[60dvh] max-sm:overflow-y-auto">

                <span class="text-md font-display">Apps</span>

                <nav class="grid grid-cols-3 gap-x-3 gap-y-4" aria-label="Apps">

                    <?php foreach ($headerApps as $app): ?>
                        <?php if ($app['key'] === 'messages') { continue; } ?>

                        <?= view('components/app-tile', [
                            'app' => $app,
                            'variant' => 'menu',
                            'active' => $app['key'] === ($page ?? null),
                        ], ['saveData' => false]) ?>

                    <?php endforeach; ?>

                </nav>

            </div>
        </div>
        <?php endif; ?>
        </div>

        <div class="relative">
            <button
                id="profile-menu-button"
                data-profile-menu-trigger
                type="button"
                aria-label="Account menu"
                aria-haspopup="menu"
                aria-expanded="false"
                aria-controls="profile-menu-panel"
                class="max-sm:hidden h-11 flex items-center gap-5 p-1 pr-3 border border-gray-300 rounded-4xl cursor-pointer transition duration-150 hover:-translate-y-0.5 hover:border-brand/50 hover:bg-white hover:shadow-md active:translate-y-0 active:scale-95 motion-reduce:transition-none motion-reduce:hover:translate-y-0 motion-reduce:active:scale-100 aria-expanded:border-brand aria-expanded:bg-brand/10 aria-expanded:shadow-md focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/55 focus-visible:ring-offset-2">
                <img
                    class="h-full w-auto rounded-full"
                    src="<?= $avatarUrl ?>"
                    alt="">

                <!-- arrow-down svg -->
                <svg
                    id="profile-menu-arrow"
                    class="h-5 w-5 text-gray-500 transition duration-200 group-hover:text-brand"
                    xmlns="http://www.w3.org/2000/svg"
                    viewBox="0 0 24 24"
                    fill="none"
                    stroke="currentColor"
                    stroke-width="2.5"
                    stroke-linecap="round"
                    stroke-linejoin="round"
                    aria-hidden="true"
                >
                    <path d="M6 9l6 6 6-6" />
                </svg>
            </button>

            <?php // Phones: Messages, dark mode, Apps and the account all sit behind this one button. ?>
            <button
                type="button"
                data-profile-menu-trigger
                aria-controls="profile-menu-panel"
                aria-haspopup="menu"
                aria-expanded="false"
                aria-label="More: <?= implode(', ', array_merge($messagesTile !== null ? ['messages'] : [], $userRole !== 'institutional_admin' ? ['apps'] : [], ['account'])) ?>"
                class="sm:hidden relative h-11 w-11 flex justify-center items-center rounded-full cursor-pointer transition duration-150 text-brand hover:bg-brand-tint hover:text-brand-dark active:scale-95 motion-reduce:transition-none motion-reduce:active:scale-100 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/55 aria-expanded:bg-brand aria-expanded:text-white">
                <svg class="h-5 w-5" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                    <circle cx="5" cy="12" r="2" /><circle cx="12" cy="12" r="2" /><circle cx="19" cy="12" r="2" />
                </svg>
                <?php if ($messagesTile !== null): ?>
                    <span data-messages-dot <?= (int) $messagesTile['badge'] > 0 ? '' : 'hidden' ?> class="absolute right-1.5 top-1.5 h-2.5 w-2.5 rounded-full bg-[#C33939] ring-2 ring-white"><span class="sr-only">unread messages</span></span>
                <?php endif; ?>
            </button>

            <div
                id="profile-menu-panel"
                data-header-panel
                class="hidden z-50 absolute right-0 top-full mt-2 w-72 overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-lg max-sm:fixed max-sm:inset-x-2.5 max-sm:top-[calc(var(--app-header-h,4.75rem)+0.5rem)] max-sm:mt-0 max-sm:w-auto max-sm:max-h-[60dvh] max-sm:overflow-y-auto">

                <?php // Phones only: what the header has no room for. ?>
                <div class="sm:hidden flex flex-col border-b border-gray-100 p-2" data-profile-phone-block>
                    <?php $phoneRow = 'flex w-full items-center gap-3 rounded-lg px-3 py-2 text-left text-sm text-[#1A1C1E] transition hover:bg-gray-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/50 pointer-coarse:min-h-11'; ?>

                    <?php if ($messagesTile !== null): ?>
                        <a href="<?= esc($messagesTile['url']) ?>" <?= ($page ?? null) === 'messages' ? 'aria-current="page"' : '' ?> class="<?= $phoneRow ?>">
                            <?= icon('chat', 'h-4 w-4 text-brand') ?>
                            <span class="flex-1">Messages</span>
                            <span data-messages-badge data-url="<?= esc(site_url('messages/api/unread-count')) ?>" <?= (int) $messagesTile['badge'] > 0 ? '' : 'hidden' ?> class="min-w-[18px] rounded-full bg-[#C33939] px-1 text-center text-[10px] font-semibold leading-[18px] text-white"><?= (int) $messagesTile['badge'] > 99 ? '99+' : (int) $messagesTile['badge'] ?></span>
                        </a>
                    <?php endif; ?>

                    <?php // theme.js keeps this button's aria-label and aria-pressed in step with the header one. ?>
                    <button type="button" data-theme-toggle aria-pressed="false" aria-label="Dark mode is off. Switch to dark mode" title="Switch to dark mode" class="<?= $phoneRow ?>">
                        <?= icon('night', 'h-4 w-4 text-brand') ?>
                        <span class="flex-1">Dark mode</span>
                    </button>

                    <?php if ($userRole !== 'institutional_admin'): ?>
                        <details class="group/apps">
                            <summary class="<?= $phoneRow ?> cursor-pointer list-none [&::-webkit-details-marker]:hidden">
                                <?= icon('apps', 'h-4 w-4 text-brand') ?>
                                <span class="flex-1">Apps</span>
                                <svg class="h-4 w-4 text-gray-500 transition group-open/apps:rotate-180 motion-reduce:transition-none" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 9l6 6 6-6" /></svg>
                            </summary>

                            <nav class="grid grid-cols-3 gap-x-3 gap-y-4 px-3 pb-2 pt-3" aria-label="Apps">
                                <?php foreach ($headerApps as $app): ?>
                                    <?php if ($app['key'] === 'messages') { continue; } ?>

                                    <?= view('components/app-tile', [
                                        'app' => $app,
                                        'variant' => 'menu',
                                        'active' => $app['key'] === ($page ?? null),
                                    ], ['saveData' => false]) ?>
                                <?php endforeach; ?>
                            </nav>
                        </details>
                    <?php endif; ?>
                </div><!-- /phone block -->

                <!-- Identity -->
                <div class="flex items-center gap-3 bg-brand-tint px-4 py-4">

                    <img
                        class="h-12 w-12 shrink-0 rounded-full object-cover"
                        src="<?= $avatarUrl ?>"
                        alt="Profile photo of <?= esc($displayName, 'attr') ?>">

                    <div class="flex min-w-0 flex-col gap-0.5">

                        <span class="truncate text-sm font-bold text-[#2F2F2F]" data-profile-name>
                            <?= esc($displayName) ?>
                        </span>

                        <div class="flex min-w-0 items-center gap-1.5">

                            <span class="shrink-0 rounded-full bg-brand/10 px-2 py-0.5 text-[10px] font-bold text-brand" data-profile-role>
                                <?= esc(ucfirst($userRole)) ?>
                            </span>

                            <?php if ($studentMeta !== ''): ?>
                                <span class="truncate text-xs text-gray-600" data-profile-student-meta>
                                    <?= esc($studentMeta) ?>
                                </span>
                            <?php endif; ?>

                        </div>

                        <?php if ($email !== '' && $email !== $displayName): ?>
                            <span class="truncate text-xs text-gray-400" title="<?= esc($email, 'attr') ?>">
                                <?= esc($email) ?>
                            </span>
                        <?php endif; ?>

                    </div>

                </div>

                <?php if ($canSwitchRole): ?>
                    <div class="border-b border-gray-100 px-4 py-3">
                        <p class="mb-2 text-[11px] font-semibold uppercase tracking-wide text-gray-500">
                            Switch role
                        </p>

                        <div
                            class="flex flex-col gap-1"
                            data-role-switch
                            data-role-switch-url="<?= esc(site_url('role/switch'), 'attr') ?>">
                            <?php foreach ($userRoles as $role): ?>
                                <button
                                    type="button"
                                    class="flex items-center justify-between rounded-lg px-3 py-2 text-left text-sm transition hover:bg-brand-tint disabled:cursor-wait disabled:opacity-60"
                                    data-role-switch-button
                                    data-role="<?= esc($role, 'attr') ?>"
                                    <?= $role === $activeRole ? 'aria-current="true" disabled' : '' ?>>
                                    <span><?= esc(ucfirst($role)) ?></span>
                                    <?php if ($role === $activeRole): ?>
                                        <span class="text-xs font-semibold text-brand">Active</span>
                                    <?php endif; ?>
                                </button>
                            <?php endforeach; ?>
                        </div>

                        <p class="mt-2 hidden text-xs text-red-600" data-role-switch-error role="alert"></p>
                    </div>
                <?php endif; ?>

                <!-- Links -->
                <nav class="flex flex-col p-2" aria-label="Account">

                    <?php foreach ($accountLinks as $link): ?>

                        <?php if (! empty($link['url'])): ?>

                            <a
                                href="<?= esc($link['url']) ?>"
                                class="flex items-center gap-3 rounded-lg px-3 py-2 text-sm text-[#1A1C1E] transition hover:bg-gray-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/50">

                                <?= icon($link['icon'], 'h-4 w-4 text-brand') ?>

                                <span class="flex-1"><?= esc($link['label']) ?></span>

                            </a>

                            <?php continue; ?>

                        <?php endif; ?>

                        <span
                            class="flex cursor-default items-center gap-3 rounded-lg px-3 py-2 text-sm text-gray-500"
                            aria-disabled="true"
                            title="Coming soon">

                            <?= icon($link['icon'], 'h-4 w-4 text-gray-400') ?>

                            <span class="flex-1"><?= esc($link['label']) ?></span>

                            <span class="rounded-full bg-gray-100 px-2 py-0.5 text-[10px] font-semibold text-gray-400">Soon</span>

                        </span>

                    <?php endforeach; ?>

                    <div class="my-1 border-t border-gray-100"></div>

                    <?php // A button, not a link: only a deliberate press signs out. ?>
                    <form action="<?= site_url('logout') ?>" method="post">

                        <?= csrf_field() ?>

                        <button
                            type="submit"
                            class="flex w-full items-center gap-3 rounded-lg px-3 py-2 text-left text-sm font-semibold text-red-600 transition hover:bg-red-50">

                            <?= icon('logout', 'h-4 w-4') ?>

                            Log out

                        </button>

                    </form>

                </nav>
            </div>
        </div>
    </div>
</header>
