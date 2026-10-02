<?php
/*
|--------------------------------------------------------------------------
| Progress card
|--------------------------------------------------------------------------
|   student    → their own completion (lessons + tasks) in the brand-blue card
|   instructor → the class's completion per module, so a stalled module
|                stands out before any student has to say so
*/

$barColor = static fn (string $tone) => match ($tone) {
    'danger' => 'bg-red-600',
    'warning' => 'bg-amber-600',
    default => 'bg-emerald-600',
};
?>

<?php if ($role === 'student'): ?>

    <div class="flex flex-col gap-8 p-6 pb-8 bg-[#0040A6] rounded-2xl text-white">

        <div class="flex flex-col gap-4">

            <div class="flex justify-between items-end">
                <span class="text-2xl font-bold">
                    Overall Progress
                </span>

                <span class="text-4xl font-black">
                    <?= esc($progress['percent']) ?>%
                </span>
            </div>

            <div class="h-3 bg-white/20 rounded-4xl overflow-clip">
                <div
                    class="h-3 bg-white/80 rounded-4xl"
                    style="width: <?= esc($progress['percent']) ?>%;"></div>
            </div>

        </div>

        <div class="flex justify-between items-center">

            <div class="flex gap-3 items-center">
                <?= icon('lessons', 'h-6 w-6 text-white') ?>

                <span class="text-md">
                    <?= esc($progress['lessons_completed']) ?>/<?= esc($progress['lessons_total']) ?> Lessons
                </span>
            </div>

            <div class="flex gap-3 items-center">
                <?= icon('tasks', 'h-6 w-6 text-white') ?>

                <span class="text-md">
                    <?= esc($progress['tasks_completed']) ?>/<?= esc($progress['tasks_total']) ?> Tasks
                </span>
            </div>

        </div>

    </div>

<?php else: ?>

    <div class="flex flex-col gap-6 p-6 pb-8 border border-[#A9A9A9]/50 rounded-2xl">

        <div class="flex justify-between items-center">

            <span class="text-2xl font-bold text-[#0040A6]">
                Class Progress
            </span>

            <span class="text-sm text-[#767676]">by module</span>

        </div>

        <?php if ($class_progress['modules'] === []): ?>

            <div class="flex flex-col items-center justify-center gap-1 py-6 text-center">

                <span class="text-sm font-bold text-[#646464]">
                    <?= $class_progress['roster'] === 0 ? 'No students enrolled yet' : 'No published modules yet' ?>
                </span>

                <span class="text-xs text-[#767676]">
                    <?= $class_progress['roster'] === 0
                        ? 'Completion shows up here once students join this section.'
                        : 'Publish a module and completion will show up here.' ?>
                </span>

            </div>

        <?php else: ?>

            <div class="flex flex-col gap-4">

                <?php foreach ($class_progress['modules'] as $module): ?>

                    <div class="flex flex-col gap-1.5">

                        <div class="flex justify-between items-center gap-3 text-sm">
                            <span class="truncate text-[#646464]"><?= esc($module['title']) ?></span>
                            <span class="shrink-0 font-bold text-[#0040A6]"><?= esc($module['completion']) ?>%</span>
                        </div>

                        <div class="h-2 bg-black/10 rounded-full overflow-clip">
                            <div
                                class="h-full <?= $barColor($module['tone']) ?> rounded-full"
                                style="width: <?= esc($module['completion']) ?>%;"></div>
                        </div>

                    </div>

                <?php endforeach; ?>

            </div>

        <?php endif; ?>

    </div>

<?php endif; ?>
