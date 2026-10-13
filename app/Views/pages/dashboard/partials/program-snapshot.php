<?php
/**
 * R3's "program snapshot": how many courses, instructors and students sit
 * under every sub-department this chairperson chairs, right now. Same
 * full-width-tile shape as the student dashboard's Key Metrics, since both
 * sit in the identity column next to a fixed-height widget and need to grow
 * to match it.
 */
$courses = (int) ($data['courses'] ?? 0);
$instructors = (int) ($data['instructors'] ?? 0);
$students = (int) ($data['students'] ?? 0);

$metrics = [
    ['label' => 'Courses',     'value' => $courses,     'icon' => 'hat',    'accent' => 'bg-blue-800'],
    ['label' => 'Instructors', 'value' => $instructors, 'icon' => 'user',   'accent' => 'bg-emerald-600'],
    ['label' => 'Students',    'value' => $students,    'icon' => 'lessons', 'accent' => 'bg-amber-600'],
];
?>

<!-- program snapshot -->
<div class="h-full min-h-0 flex flex-col gap-4">

    <?php foreach ($metrics as $metric): ?>

        <div class="flex-1 min-h-0 flex items-center justify-between gap-3 bg-white/20 backdrop-blur-xl border border-white/30 shadow-xl rounded-3xl px-5 py-3">

            <div class="flex items-center gap-3 min-w-0">
                <div class="w-9 h-9 shrink-0 flex justify-center items-center <?= $metric['accent'] ?> rounded-full">
                    <?= icon($metric['icon'], 'w-5 h-5 text-white') ?>
                </div>

                <span class="text-xs text-gray-600 leading-tight">
                    <?= esc($metric['label']) ?>
                </span>
            </div>

            <span class="text-3xl font-bold font-display shrink-0">
                <?= esc((string) $metric['value']) ?>
            </span>

        </div>

    <?php endforeach; ?>

</div>
