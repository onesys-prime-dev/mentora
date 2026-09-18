<?php 
$userRole = esc($user->getGroups()[0]);
?>

<?= $this->extend('layouts/app') ?>



<?= $this->section('content') ?>

<div class="flex justify-between items-center">
    <?= $this->include('components/back') ?>

    <?= $this->include('components/search') ?>
</div>

<div class="grid grid-cols-5 gap-5 px-1 py-3">

    <?php foreach ($courses as $course): ?>

        <a 
            class="relative h-80 flex flex-1 flex-col justify-between p-6 rounded-3xl bg-cyan-200 bg-cover bg-center bg-no-repeat text-white overflow-hidden"
            style="background-image: url('<?= base_url('assets/images/placeholder/course-tech-1.png') ?>');"
            href="<?= site_url($userRole . '/courses/' . $course['offering_id'] . '/' . $course['slug']) ?>">

            <div class="pointer-events-none absolute inset-x-0 bottom-0 h-2/3 bg-gradient-to-t from-black/80 via-black/30 to-transparent"></div>

            <div class="relative z-10 flex justify-between items-center">

                <div class="w-12 h-12 flex justify-center items-center rounded-[100%] bg-blue-900">
                    <img 
                        class="w-5 h-auto"
                        src="<?= base_url('assets/images/icons/code.png') ?>" alt="">
                </div>

                <div class="flex flex-col justify-end">
                    <span class="text-md text-end">8:00 - 9:30</span>
                    <span class="text-xs text-end opacity-70">Mon, Wed, Fri</span>
                </div>

            </div>

            <div class="relative z-10 flex flex-col">

                <div class="flex flex-col border-b border-b-gray-50 py-3">
                    <div class="flex gap-1 text-md items-center opacity-70">
                        <span class=""><?= esc($course['course_code']) ?></span>
                        <span class="">-</span>
                        <span class=""><?= esc($course['section_code']) ?></span>
                    </div>

                    <span class="font-bold text-2xl">
                        <?= esc($course['course_title']) ?>
                    </span>
                </div>

                <?php if (isset($course['instructor_name'])): ?>
                    <div class="flex gap-2 items-center py-3">
                        <img 
                            src="<?= base_url('assets/images/placeholder/profile.png') ?>"
                            class="w-6 h-6"
                            alt="">
    
                            <span class="text-xs opacity-70">
                                Instr. <?= esc($course['instructor_name']) ?> - Room 33A
                            </span>
                    </div>
                <?php endif; ?>

                <div class="flex flex-col mt-3">
                    <div class="flex justify-between">
                        <span class="text-xs opacity-60">PROGRESS</span>

                        <span class="text-xs opacity-60">25%</span>
                    </div>

                    <div class="h-2 bg-amber-50 rounded-2xl">
                        <div class="h-2 w-20 bg-amber-300 rounded-2xl"></div>
                    </div>
                </div>

            </div>
        </a>

    <?php endforeach; ?>

</div>

<?= $this->endSection() ?>