<?php
$userRole = esc($user->getGroups()[0]);

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
            href="<?= site_url($userRole . '/dashboard') ?>">
            <img
                class="h-11 w-auto"
                src="<?= base_url('assets/images/logos/Mentora_logo_type.png') ?>" alt="">
        </a>

        <div class="flex items-end">
            <span class="text-2xl font-semibold text-[#0040A6] capitalize pb-px">
                <?php if ($page !== null && $page !== 'dashboard'): ?>
                    <?= esc($page ?? '') ?>
                <?php endif; ?>
            </span>
        </div>
    </div>

    <div class="flex gap-4 items-center">
        <button
            class="h-10 w-10 flex justify-center items-center bg-white rounded-4xl shadow cursor-pointer">
            <?= icon('bell', 'h-4 w-4') ?>
        </button>
        <button
            class="h-10 w-10 flex justify-center items-center bg-white rounded-4xl shadow cursor-pointer">
            <?= icon('night', 'h-4 w-4') ?>
        </button>

        <div class="relative">
            <button
                id="app-menu-button"
                type="button"
                aria-label="Apps"
                aria-haspopup="menu"
                aria-expanded="false"
                aria-controls="app-menu-panel"
                class="h-10 w-10 flex justify-center items-center bg-white rounded-4xl shadow cursor-pointer">
                <?= icon('apps', 'h-4 w-4') ?>
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
                class="h-11 flex items-center gap-5 p-1 pr-3 border border-gray-300 rounded-4xl cursor-pointer">
                <img
                    class="h-full w-auto rounded-full"
                    src="<?= $avatarUrl ?>"
                    alt="">

                <!-- arrow-down svg -->
                <svg
                    id="profile-menu-arrow"
                    class="h-5 w-5 text-gray-500 transition-transform duration-200"
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
                <div class="flex items-center gap-3 bg-[#F2F7FF] px-4 py-4">

                    <img
                        class="h-12 w-12 shrink-0 rounded-full object-cover"
                        src="<?= $avatarUrl ?>"
                        alt="Profile photo of <?= esc($displayName, 'attr') ?>">

                    <div class="flex min-w-0 flex-col gap-0.5">

                        <span class="truncate text-sm font-bold text-[#2F2F2F]" data-profile-name>
                            <?= esc($displayName) ?>
                        </span>

                        <div class="flex min-w-0 items-center gap-1.5">

                            <span class="shrink-0 rounded-full bg-[#0040A6]/10 px-2 py-0.5 text-[10px] font-bold text-[#0040A6]" data-profile-role>
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
