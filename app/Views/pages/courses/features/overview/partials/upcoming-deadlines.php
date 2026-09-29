<?php
/*
|--------------------------------------------------------------------------
| Upcoming deadlines
|--------------------------------------------------------------------------
| Rows come from OverviewService already shaped for the date tiles
| (title, month, day, tone, detail). The tone drives the tile colour:
|   danger  → overdue          warning → due within the week
|   neutral → later
|
|   student    → what I owe, soonest first
|   instructor → what's closing, and how many have handed in
|
| A row opens its assessment (bound in overview/index.js): the student's
| view of it, or Grade Work for an instructor.
*/

$tile = static fn (string $tone) => match ($tone) {
    'danger' => 'bg-[#F8E8E8] text-[#C33939]',
    'warning' => 'bg-[#FFF1D6] text-[#946200]',
    default => 'bg-[#E5E8F0] text-[#153274]',
};

$isStudent = $role === 'student';
?>

<div class="flex flex-col gap-6 p-6 pb-8 border border-[#A9A9A9]/50 rounded-2xl">

    <div class="flex justify-between items-center">

        <span class="text-2xl font-bold text-[#0040A6]">
            <?= $isStudent ? 'Upcoming Deadlines' : 'Closing Soon' ?>
        </span>

        <button
            type="button"
            class="text-sm text-[#0040A6] cursor-pointer"
            data-course-feature="assessments">
            View All
        </button>

    </div>

    <?php if ($deadlines === []): ?>

        <div class="flex flex-col items-center justify-center gap-1 py-6 text-center">

            <span class="text-sm font-bold text-[#646464]">
                <?= $isStudent ? "You're all caught up" : 'Nothing closing soon' ?>
            </span>

            <span class="text-xs text-[#878787]">
                <?= $isStudent
                    ? 'New deadlines will show up here as they are published.'
                    : 'Published assessments with an upcoming due date will show up here.' ?>
            </span>

        </div>

    <?php else: ?>

        <div class="flex flex-col gap-4">

            <?php foreach ($deadlines as $deadline): ?>

                <div
                    class="group flex items-center gap-5 py-2 px-3 -mx-3 rounded-2xl cursor-pointer transition-colors duration-150 hover:bg-[#0040A6]/5"
                    data-overview-open="<?= (int) $deadline['id'] ?>">

                    <div class="w-12 h-12 shrink-0 flex flex-col justify-center items-center <?= $tile($deadline['tone']) ?> rounded-xl">

                        <?php if ($deadline['month'] !== ''): ?>
                            <span class="uppercase text-xs leading-none">
                                <?= esc($deadline['month']) ?>
                            </span>
                        <?php endif; ?>

                        <span class="text-xl font-bold leading-none">
                            <?= esc($deadline['day']) ?>
                        </span>

                    </div>

                    <div class="flex min-w-0 flex-col">

                        <span class="truncate text-md text-black/70 font-bold transition-colors duration-150 group-hover:text-[#0040A6]">
                            <?= esc($deadline['title']) ?>
                        </span>

                        <div class="flex items-center gap-2">
                            <?= icon('time-mono', 'h-3 w-3') ?>

                            <span class="truncate text-sm text-black/50">
                                <?= esc($deadline['detail']) ?>
                            </span>
                        </div>

                    </div>

                    <svg
                        xmlns="http://www.w3.org/2000/svg"
                        class="ml-auto h-5 w-5 shrink-0 text-[#A9A9A9] transition-transform duration-200 group-hover:translate-x-1 group-hover:text-[#0040A6]"
                        fill="none"
                        viewBox="0 0 24 24"
                        stroke="currentColor"
                        stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7" />
                    </svg>

                </div>

            <?php endforeach; ?>

        </div>

    <?php endif; ?>

</div>
