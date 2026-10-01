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

        <?php $progress = max(0, min(100, (int) ($course['progress'] ?? 0))); ?>

        <a
            class="group relative h-80 flex flex-1 flex-col justify-between p-6 rounded-3xl bg-cyan-200 bg-cover bg-center bg-no-repeat text-white overflow-hidden
                    transition-transform duration-200 ease-out
                    hover:-translate-y-1 hover:shadow-2xl
                    active:translate-y-0 active:scale-[0.99]
                    focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-white/70 focus-visible:ring-offset-2 focus-visible:ring-offset-transparent"
            style="background-image: url('<?= base_url('assets/images/placeholder/course-tech-1.png') ?>');"
            href="<?= site_url($userRole . '/courses/' . $course['offering_id'] . '/' . $course['slug']) ?>">

            <div class="pointer-events-none absolute inset-x-0 bottom-0 h-2/3 bg-gradient-to-t from-black/80 via-black/30 to-transparent"></div>

            <svg
                xmlns="http://www.w3.org/2000/svg"
                class="pointer-events-none absolute top-6 right-6 z-10 h-5 w-5 opacity-0 -translate-x-1 transition-all duration-200 group-hover:opacity-70 group-hover:translate-x-0"
                fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7" />
            </svg>

            <div class="relative z-10 flex justify-between items-center">

                <div class="w-12 h-12 flex justify-center items-center rounded-[100%] bg-blue-900 transition-transform duration-200 group-hover:scale-110">
                    <?= icon('code', 'h-5 w-5') ?>
                </div>

                <div class="flex flex-col justify-end">
                    <span class="text-md text-end"><?= esc($course['schedule'] ?: 'No schedule set') ?></span>
                    <span class="text-xs text-end opacity-70">Room <?= esc($course['room'] ?? '--') ?></span>
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
                                Instr. <?= esc($course['instructor_name']) ?>
                            </span>
                    </div>
                <?php endif; ?>

                <div class="flex flex-col mt-3">
                    <div class="flex justify-between">
                        <span class="text-xs opacity-60">PROGRESS</span>

                        <span class="text-xs opacity-60"><?= $progress ?>%</span>
                    </div>

                    <div class="h-2 bg-amber-50 rounded-2xl">
                        <div class="h-2 bg-amber-300 rounded-2xl transition-[width] duration-300" style="width: <?= $progress ?>%;"></div>
                    </div>
                </div>

            </div>
        </a>

    <?php endforeach; ?>

</div>

<?= $this->endSection() ?>