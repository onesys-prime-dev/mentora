<?php
/**
 * Full-width metric rows. Sitting under the compact profile strip, these
 * grow to fill the identity column so it ends level with the taller cards
 * beside it — which is why each tile is flex-1 rather than a fixed size.
 *
 * Student-only: instructors get Teaching Load in this slot instead, since
 * counters of sections and students only repeat what their widgets say.
 */
$coursesEnrolled = (int) ($data['courses_enrolled'] ?? 0);
$pendingTasks = (int) ($data['pending_tasks'] ?? 0);
$averageGrade = $data['average_grade'] ?? null;

$metrics = [
    [
        'label' => 'Courses Enrolled',
        'value' => $coursesEnrolled,
        'icon'  => 'hat',
        'accent' => 'bg-blue-800',
    ],
    [
        'label' => 'Pending Tasks',
        // Zero reads as good news here, not an empty state, so it gets its
        // own affirming copy rather than a bare "0".
        'value' => $pendingTasks > 0 ? $pendingTasks : 'All caught up',
        'small' => $pendingTasks === 0,
        'icon'  => 'tasks',
        'accent' => 'bg-amber-600',
    ],
    [
        'label' => 'Average Grade',
        'value' => $averageGrade !== null ? number_format($averageGrade, 1) . '%' : 'No grades yet',
        'small' => $averageGrade === null,
        'icon'  => 'lessons',
        'accent' => 'bg-emerald-600',
    ],
];
?>

<!-- key metrics -->
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

            <span class="<?= !empty($metric['small']) ? 'text-base font-semibold text-gray-600' : 'text-3xl font-bold font-display' ?> shrink-0">
                <?= esc($metric['value']) ?>
            </span>

        </div>

    <?php endforeach; ?>

</div>
