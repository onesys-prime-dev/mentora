<?php 
$userRole = esc($user->getGroups()[0]);
?>

<?= $this->extend('layouts/app') ?>



<?= $this->section('content') ?>

<h1 class="text-3xl font-bold">My Courses</h1>

<div class="grid grid-cols-5 gap-8 px-1 py-3">

    <?php foreach ($courses as $course): ?>

        <a class="h-80 flex flex-1 flex-col justify-between p-6 rounded-3xl bg-cyan-200"
            href="<?= site_url($userRole . '/courses/' . $course['offering_id'] . '/' . $course['slug'] . '/home') ?>">

            <div class="flex justify-between items-center">

                <div class="w-10 h-10 flex justify-center items-center rounded-[100%] bg-gray-400">
                    O
                </div>

                <div class="flex flex-col justify-end">
                    <span class="">8:00 - 9:30</span>
                    <span class="">Mon, Wed, Fri</span>
                </div>

            </div>

            <div class="flex flex-col">

                <div class="flex flex-col border-0 border-b-gray-400">
                    <span>
                        <?= esc($course['course_code']) . '-' . esc($course['section_code']) ?>
                    </span>

                    <span>
                        <?= esc($course['course_title']) ?>
                    </span>
                </div>

                <div class="flex gap-1 items-center">
                    <div class="w-5 h-5 flex justify-center items-center rounded-[100%] bg-gray-400">

                    </div>

                    <span>
                        Prof. Jalen Brunson - Room 33A
                    </span>
                </div>

                <div class="flex flex-col">
                    <div class="flex justify-between">
                        <span>PROGRESS</span>

                        <span>25%</span>
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