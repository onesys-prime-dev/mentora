<?php 
$userRole = esc($user->getGroups()[0]);
?>

<!-- quick access -->
<div class="flex flex-col bg-white/20 backdrop-blur-xl border border-white/30 shadow-xl rounded-3xl overflow-clip">

    <div class="px-8 py-4 bg-black/10 backdrop-blur-xl">
        <span class="text-xl font-display">Quick Access</span>
    </div>

    <div class="h-full px-8 py-6 flex flex-col gap-4">

        <div class="flex gap-4">

            <a 
                class="w-18 h-18 flex justify-center items-center bg-white/30 border border-black/5 shadow-md rounded-3xl"
                href="<?= site_url($userRole . '/courses') ?>">

                <img
                    class="h-7 w-auto" 
                    src="<?= base_url('assets/images/icons/hat.png') ?>" alt="">

            </a>

            <a 
                class="w-18 h-18 flex justify-center items-center bg-white/30 border border-black/5 shadow-md rounded-3xl"
                href="<?= site_url($userRole . '/') ?>">

                <img
                    class="h-7 w-auto" 
                    src="<?= base_url('assets/images/icons/message.png') ?>" alt="">

            </a>

            <a 
                class="w-18 h-18 flex justify-center items-center bg-white/30 border border-black/5 shadow-md rounded-3xl"
                href="<?= site_url($userRole . '/') ?>">

                <img
                    class="h-7 w-auto" 
                    src="<?= base_url('assets/images/icons/calendar.png') ?>" alt="">

            </a>

        </div>

        <div class="flex gap-4">

            <a 
                class="w-18 h-18 flex justify-center items-center bg-white/30 border border-black/5 shadow-md rounded-3xl"
                href="<?= site_url($userRole . '/') ?>">

                <img
                    class="h-7 w-auto" 
                    src="<?= base_url('') ?>" alt="">

            </a>

            <a 
                class="w-18 h-18 flex justify-center items-center bg-white/30 border border-black/5 shadow-md rounded-3xl"
                href="<?= site_url($userRole . '/') ?>">

                <img
                    class="h-7 w-auto" 
                    src="<?= base_url('') ?>" alt="">

            </a>

            <a 
                class="w-18 h-18 flex justify-center items-center bg-white/30 border border-black/5 shadow-md rounded-3xl"
                href="<?= site_url($userRole . '/') ?>">

                <img
                    class="h-7 w-auto" 
                    src="<?= base_url('') ?>" alt="">

            </a>

        </div>

    </div>

</div>