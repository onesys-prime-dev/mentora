<?php 
$userRole = esc($user->getGroups()[0]);
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
    <div class="flex gap-2 items-center font-lao">
        <a 
            class="flex gap-1 items-center"
            href="<?= site_url($userRole . '/dashboard') ?>">
            <img
                class="h-11 w-auto"
                src="<?= base_url('assets/images/logos/logo.png') ?>" alt="">
            <span class="text-2xl font-normal text-[#0040A6]">
                Mentora
            </span>
        </a>
        <span class="text-2xl font-bold text-[#0040A6] capitalize">
            <?= esc($page ?? '') ?>
        </span>
    </div>

    <div class="flex gap-4 items-center">
        <button
            class="h-10 w-10 flex justify-center items-center bg-white rounded-4xl shadow cursor-pointer">
            <img 
                class="h-4 w-auto"
                src="<?= base_url('assets/images/icons/bell.png') ?>" 
                alt="">
        </button>
        <button
            class="h-10 w-10 flex justify-center items-center bg-white rounded-4xl shadow cursor-pointer">
            <img 
                class="h-4 w-auto"
                src="<?= base_url('assets/images/icons/night.png') ?>" 
                alt="">
        </button>

        <div class="relative">
            <button
                id="app-menu-button"
                type="button"
                class="h-10 w-10 flex justify-center items-center bg-white rounded-4xl shadow cursor-pointer">
                <img 
                    class="h-4 w-auto"
                    src="<?= base_url('assets/images/icons/apps.png') ?>" 
                    alt="">
            </button>

            <div
                id="app-menu-panel"
                class="hidden absolute right-0 top-full mt-2 min-w-[10rem] rounded-xl border border-gray-200 bg-white p-3 shadow-lg">

                <nav class="flex flex-col gap-2 text-center">
                    <?php if ($userRole == 'student'): ?>

                        <a href="<?= site_url($userRole . '/dashboard') ?>">Dashboard</a>
                        <a href="<?= site_url($userRole . '/courses') ?>">My Courses</a>

                    <?php elseif ($userRole == 'instructor'): ?>

                    <?php endif; ?>
                </nav>


            </div>
        </div>

        <div class="relative">
            <button
                id="profile-menu-button"
                type="button"
                class="h-11 flex items-center gap-5 p-1 pr-3 border border-gray-300 rounded-4xl cursor-pointer">
                <img 
                    class="h-full w-auto"
                    src="<?= base_url('assets/images/placeholder/profile.png') ?>" 
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
                >
                    <path d="M6 9l6 6 6-6" />
                </svg>
            </button>

            <div
                id="profile-menu-panel"
                class="hidden absolute right-0 top-full mt-2 min-w-[10rem] rounded-xl border border-gray-200 bg-white p-3 shadow-lg">

                <?= esc($userRole) ?>

                <nav class="flex flex-col">
                    <a href="<?= site_url('/logout') ?>">Logout</a>
                </nav>
            </div>
        </div>
    </div>
</header>