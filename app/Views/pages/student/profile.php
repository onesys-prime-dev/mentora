<?php
/**
 * The student's own profile, read only: who they are and which parents can
 * see their progress.
 *
 * @var string       $accountName
 * @var string       $accountEmail
 * @var string       $studentNumber empty when none is set
 * @var list<string> $parentNames   the parents who can see their progress now, by name
 */
?>

<?= $this->extend('layouts/app') ?>

<?= $this->section('content') ?>

<div class="flex flex-col gap-6 py-3">

    <?= view('components/page-head', [
        'title'       => 'Profile',
        'icon'        => 'user',
        'description' => 'Who you are in Mentora, and who can see your progress.',
        'backUrl'     => site_url('student/dashboard'),
        'backLabel'   => 'Back to dashboard',
    ], ['saveData' => false]) ?>

    <section class="flex max-w-2xl flex-col gap-4 rounded-2xl bg-white p-6 shadow-sm" aria-labelledby="student-account" data-student-account>
        <h2 id="student-account" class="text-sm font-bold text-brand">Your account</h2>

        <dl class="grid grid-cols-1 gap-x-6 gap-y-3 text-sm sm:grid-cols-[9rem_1fr]">
            <dt class="font-medium text-gray-600">Name</dt>
            <dd class="text-[#1A1C1E]"><?= esc($accountName) ?></dd>

            <dt class="font-medium text-gray-600">Email</dt>
            <dd class="text-[#1A1C1E]"><?= esc($accountEmail) ?></dd>

            <dt class="font-medium text-gray-600">Student number</dt>
            <dd class="text-[#1A1C1E]"><?= $studentNumber !== '' ? esc($studentNumber) : '<span class="text-[#646464]">Not set</span>' ?></dd>
        </dl>

        <p class="text-xs text-[#646464]">To change any of these, contact your school.</p>
    </section>

    <section class="flex max-w-2xl flex-col gap-3 rounded-2xl bg-white p-6 shadow-sm" aria-labelledby="student-parents" data-student-parents>
        <h2 id="student-parents" class="text-sm font-bold text-brand">Parents who can see your progress</h2>

        <?php if ($parentNames === []): ?>

            <p class="text-sm text-[#646464]">No parent can see your progress.</p>

        <?php else: ?>

            <ul class="flex flex-col divide-y divide-gray-100">
                <?php foreach ($parentNames as $name): ?>
                    <li class="flex items-center gap-3 py-2 text-sm font-semibold text-[#1A1C1E]">
                        <?= icon('user', 'h-4 w-4 shrink-0 text-brand') ?>
                        <?= esc($name) ?>
                    </li>
                <?php endforeach; ?>
            </ul>

            <p class="text-xs text-[#646464]">They can see your courses, deadlines and grades. They cannot change anything. Your school decides who is linked to you.</p>

        <?php endif; ?>
    </section>

</div>

<?= $this->endSection() ?>
