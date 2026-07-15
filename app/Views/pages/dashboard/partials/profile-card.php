<?php

$fullName = trim(($data['first_name'] ?? '') . ' ' . ($data['last_name'] ?? ''));

$program = isset($data['program_name'])
    ? str_replace('Bachelor of Science in', 'BS in', $data['program_name'])
    : null;

?>

<!-- profile card -->
<div class="flex flex-col justify-between bg-white/20 backdrop-blur-xl border border-white/30 shadow-xl rounded-3xl p-8">

    <div class="">
        <img 
            class="w-30 h-30"
            src="<?= base_url('assets/images/placeholder/profile.png') ?>" alt="">
    </div>

    <div class="flex flex-col gap-1">
        <span class="text-3xl font-bold">
            <?= esc($fullName) ?>
        </span>

        <span class="text-md font-normal text-gray-700">
            <?= esc($data['student_number'] ?? '') ?>
        </span>

        <?php if ($program !== null): ?>
            <span class="text-md font-normal text-gray-700">
                <?= esc($program) ?>
            </span>
        <?php endif; ?>
    </div>

</div>