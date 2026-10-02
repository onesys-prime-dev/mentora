<?php
/*
|--------------------------------------------------------------------------
| Recent activity
|--------------------------------------------------------------------------
| $activity comes from ActivityFeedService, newest first:
|   kind, title, detail, when
| Events that are about someone's work — a grade posted to a student, work
| handed in to an instructor — get the brand-blue dot; course changes
| (modules, new assessments, people joining) get a quieter one.
*/

$emphasis = ['grade', 'submission'];
?>

<div class="flex flex-col gap-6 p-6 pb-8 border border-[#A9A9A9]/50 rounded-2xl">

    <span class="text-2xl font-bold text-[#0040A6]">
        Recent Activity
    </span>

    <?php if ($activity === []): ?>

        <div class="flex flex-col items-center justify-center gap-1 py-6 text-center">

            <span class="text-sm font-bold text-[#646464]">No recent activity</span>

            <span class="text-xs text-[#767676]">
                <?= $role === 'student'
                    ? 'Grades and course updates from the last 30 days will show up here.'
                    : 'Submissions and new students from the last 30 days will show up here.' ?>
            </span>

        </div>

    <?php else: ?>

        <div class="flex flex-col">

            <?php foreach ($activity as $index => $event): ?>

                <?php $isLast = $index === array_key_last($activity); ?>

                <div class="flex gap-5 px-2">

                    <div class="flex flex-col items-center gap-1 pt-1">

                        <div class="w-3.5 h-3.5 shrink-0 rounded-4xl <?= in_array($event['kind'], $emphasis, true) ? 'bg-[#0040A6]' : 'bg-black/25' ?>"></div>

                        <?php if (! $isLast): ?>
                            <div class="w-0.5 flex-1 bg-black/10"></div>
                        <?php endif; ?>

                    </div>

                    <div class="flex min-w-0 flex-col <?= $isLast ? '' : 'mb-5' ?>">

                        <span class="text-md text-black/70 font-bold">
                            <?= esc($event['title']) ?>
                        </span>

                        <span class="text-sm text-black/50 wrap-break-word">
                            <?= esc($event['detail']) ?>
                        </span>

                        <span class="uppercase text-xs text-black/50 mt-2">
                            <?= esc($event['when']) ?>
                        </span>

                    </div>

                </div>

            <?php endforeach; ?>

        </div>

    <?php endif; ?>

</div>
