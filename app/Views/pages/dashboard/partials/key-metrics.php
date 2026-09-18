<?php
/**
 * Full-width metric rows. Sitting under the compact profile strip, these
 * grow to fill the identity column so it ends level with the taller cards
 * beside it — which is why each tile is flex-1 rather than a fixed size.
 *
 * Student-only: instructors get Teaching Load in this slot instead, since
 * counters of sections and students only repeat what their widgets say.
 */
$metrics = [
    [
        'label' => 'Courses Enrolled',
        'value' => $data['courses_enrolled'] ?? 0,
        'icon'  => 'hat.png',
        'accent' => 'bg-blue-800',
    ],
    [
        'label' => 'Pending Tasks',
        'value' => $data['pending_tasks'] ?? 0,
        'icon'  => 'tasks.png',
        'accent' => 'bg-amber-600',
    ],
    [
        'label' => 'Current GPA',
        'value' => $data['current_gpa'] ?? '—',
        'icon'  => 'lessons.png',
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

            <span class="text-3xl font-bold font-display shrink-0">
                <?= esc($metric['value']) ?>
            </span>

        </div>

    <?php endforeach; ?>

</div>
