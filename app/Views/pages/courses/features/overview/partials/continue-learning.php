<?php
/*
|--------------------------------------------------------------------------
| Continue where you left off (student)
|--------------------------------------------------------------------------
| The single most useful thing a student can do on arriving: one button to
| the item they were in the middle of, or the next one in course order.
| $resume comes from OverviewService::resumePoint():
|   resume → an item in progress         next  → the next incomplete item
|   done   → every item completed        empty → nothing published yet
*/

$state = $resume['state'];

$button = 'inline-flex items-center gap-2 self-start rounded-4xl bg-[#0040A6] px-5 py-2 text-sm font-semibold text-white shadow transition-colors duration-300 hover:bg-[#002068]';

$arrow = '<svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M13 7l5 5m0 0l-5 5m5-5H6" /></svg>';
?>

<div class="flex flex-col gap-5 p-6 border border-[#0040A6]/30 bg-[#0040A6]/5 rounded-2xl">

    <div class="flex items-center justify-between gap-3">

        <span class="uppercase text-sm font-bold text-[#767676]">
            <?= in_array($state, ['resume', 'next'], true) ? 'Continue where you left off' : 'Course content' ?>
        </span>

        <?php if (in_array($state, ['resume', 'next'], true)): ?>
            <span class="rounded-full bg-[#E0E5F2] px-3 py-1 text-xs font-black text-[#002068]">
                <?= esc($resume['kind']) ?>
            </span>
        <?php endif; ?>

    </div>

    <?php if (in_array($state, ['resume', 'next'], true)): ?>

        <div class="flex flex-col gap-1">

            <span class="text-sm text-[#767676]">
                <?= esc($resume['module']) ?> › <?= esc($resume['unit']) ?>
            </span>

            <span class="text-2xl font-bold text-[#0040A6]">
                <?= esc($resume['title']) ?>
            </span>

        </div>

        <?php if ($state === 'resume' && $resume['progress'] > 0): ?>

            <div class="flex items-center gap-3">

                <div class="h-2 flex-1 bg-black/10 rounded-full overflow-clip">
                    <div
                        class="h-full bg-[#0040A6] rounded-full"
                        style="width: <?= esc($resume['progress']) ?>%;"></div>
                </div>

                <span class="text-xs text-[#767676]"><?= esc($resume['progress']) ?>% watched</span>

            </div>

        <?php endif; ?>

        <div class="flex items-center justify-between gap-4">

            <a class="<?= $button ?>" href="<?= esc($resume['href']) ?>">
                <?= $state === 'resume' ? 'Resume' : ($resume['started'] ? 'Continue' : 'Start') ?>
                <?= $arrow ?>
            </a>

            <span class="text-sm text-[#767676]">
                <?= esc($resume['completed']) ?> of <?= esc($resume['total']) ?> items done
            </span>

        </div>

    <?php elseif ($state === 'done'): ?>

        <div class="flex flex-col gap-1">

            <span class="text-2xl font-bold text-[#0040A6]">
                You've finished every lesson
            </span>

            <span class="text-sm text-[#767676]">
                All <?= esc($resume['total']) ?> items are complete. Check your upcoming deadlines for what's left.
            </span>

        </div>

    <?php else: ?>

        <div class="flex flex-col gap-1">

            <span class="text-2xl font-bold text-[#0040A6]">
                No lessons yet
            </span>

            <span class="text-sm text-[#767676]">
                Your instructor hasn't published any content for this course. It'll show up here once they do.
            </span>

        </div>

    <?php endif; ?>

</div>
