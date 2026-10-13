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

// Not built yet — shown so the menu's shape is settled, but inert.
$accountLinks = [
    ['label' => 'My Profile', 'icon' => 'user'],
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
        bg-white/75
        px-6
        py-3
        shadow
    ">
    <div class="flex gap-2 items-stretch font-lao">
        <a
            class="flex gap-1 items-center self-center"
            href="<?= site_url(role_home($userRole) . '/dashboard') ?>">
            <img
                class="h-11 w-auto"
                src="<?= base_url('assets/images/logos/Mentora_logo_type.png') ?>" alt="Mentora">
        </a>

        <?php $headerBranding = (new \App\Services\Institutions\InstitutionBranding())->forUser(auth()->user()); ?>
        <?php if ($headerBranding !== null && $headerBranding['logoUrl'] !== null): ?>
            <span class="mx-1 h-8 w-px self-center bg-black/10" aria-hidden="true"></span>
            <img class="h-9 w-auto max-w-[10rem] self-center object-contain" src="<?= esc($headerBranding['logoUrl']) ?>" alt="<?= esc($headerBranding['name']) ?>" data-institution-logo>
        <?php endif; ?>

        <div class="flex items-end">
            <?php /* The page's own <h1> says where you are; the layout sets headerTitle false then. */ ?>
            <?php if ($page !== null && $page !== 'dashboard' && ($headerTitle ?? true)): ?>
                <span class="text-2xl font-semibold text-brand capitalize pb-px" data-header-title>
                    <?= esc($pageTitle ?? ucwords(str_replace(['-', '_'], ' ', (string) $page))) ?>
                </span>
            <?php endif; ?>
        </div>
    </div>

    <div class="flex gap-4 items-center">
        <a
            href="<?= site_url('notifications') ?>"
            aria-label="Notifications"
            class="h-10 w-10 flex justify-center items-center bg-white rounded-4xl shadow cursor-pointer transition duration-150 text-brand hover:-translate-y-0.5 hover:text-brand-dark hover:shadow-md active:translate-y-0 active:scale-95 motion-reduce:transition-none motion-reduce:hover:translate-y-0 motion-reduce:active:scale-100 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/55 focus-visible:ring-offset-2">
            <?= icon('bell', 'h-4 w-4 text-current') ?>
        </a>
        <button
            type="button"
            aria-label="Toggle dark mode (coming soon)" aria-disabled="true" title="Coming soon"
            class="h-10 w-10 flex justify-center items-center bg-white rounded-4xl shadow cursor-pointer transition duration-150 text-brand hover:-translate-y-0.5 hover:text-brand-dark hover:shadow-md active:translate-y-0 active:scale-95 motion-reduce:transition-none motion-reduce:hover:translate-y-0 motion-reduce:active:scale-100 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/55 focus-visible:ring-offset-2">
            <?= icon('night', 'h-4 w-4 text-current') ?>
        </button>

        <div class="relative">
            <button
                id="app-menu-button"
                type="button"
                aria-label="Apps"
                aria-haspopup="menu"
                aria-expanded="false"
                aria-controls="app-menu-panel"
                class="h-10 w-10 flex justify-center items-center bg-white rounded-4xl shadow cursor-pointer transition duration-150 text-brand hover:-translate-y-0.5 hover:text-brand-dark hover:shadow-md active:translate-y-0 active:scale-95 motion-reduce:transition-none motion-reduce:hover:translate-y-0 motion-reduce:active:scale-100 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/55 focus-visible:ring-offset-2 aria-expanded:bg-brand aria-expanded:text-white aria-expanded:shadow-md aria-expanded:hover:text-white">
                <?= icon('apps', 'h-4 w-4 text-current') ?>
            </button>

            <!-- Global apps — same list as the dashboard's Quick Access (global_apps()) -->
            <div
                id="app-menu-panel"
                class="hidden z-50 absolute right-0 top-full mt-2 w-72 flex flex-col gap-4 rounded-2xl border border-gray-200 bg-white p-5 shadow-lg">

                <span class="text-md font-display">Apps</span>

                <nav class="grid grid-cols-3 gap-x-3 gap-y-4" aria-label="Apps">

                    <?php foreach (global_apps($userRole) as $app): ?>

                        <?= view('components/app-tile', [
                            'app' => $app,
                            'variant' => 'menu',
                            'active' => $app['key'] === ($page ?? null),
                        ]) ?>

                    <?php endforeach; ?>

                </nav>

            </div>
        </div>

        <div class="relative">
            <button
                id="profile-menu-button"
                type="button"
                aria-label="Account menu"
                aria-haspopup="menu"
                aria-expanded="false"
                aria-controls="profile-menu-panel"
                class="h-11 flex items-center gap-5 p-1 pr-3 border border-gray-300 rounded-4xl cursor-pointer transition duration-150 hover:-translate-y-0.5 hover:border-brand/50 hover:bg-white hover:shadow-md active:translate-y-0 active:scale-95 motion-reduce:transition-none motion-reduce:hover:translate-y-0 motion-reduce:active:scale-100 aria-expanded:border-brand aria-expanded:bg-brand/10 aria-expanded:shadow-md focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/55 focus-visible:ring-offset-2">
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

            <div
                id="profile-menu-panel"
                class="hidden z-50 absolute right-0 top-full mt-2 w-72 overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-lg">

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

                    <a
                        href="<?= site_url('/logout') ?>"
                        class="flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-semibold text-red-600 transition hover:bg-red-50">

                        <?= icon('logout', 'h-4 w-4') ?>

                        Log out

                    </a>

                </nav>
            </div>
        </div>
    </div>
</header>
