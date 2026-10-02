<?php

/*
|--------------------------------------------------------------------------
| Key metric cards — Assessments' own look
|--------------------------------------------------------------------------
| Deliberately not the bordered-box-with-icon-bubble card used everywhere
| else (Grades, Overview, Dashboard): a tinted panel with a coloured left
| accent bar instead, so urgency reads from the tone of the whole card at
| a glance rather than from a small bubble in the corner — the tone here
| is the thing that matters most on this specific page (what's overdue,
| what's missing).
|
| Built by AssessmentOverviewService:
|   student    → Due This Week · Overdue · Completed
|   instructor → To Grade · Due This Week · Submission Rate · Missing Work
| Each: label, icon, tone (neutral|warning|danger), value, detail.
|--------------------------------------------------------------------------
*/

$metrics ??= [];

$columns = count($metrics) >= 4 ? 'lg:grid-cols-4' : 'lg:grid-cols-3';

$palette = static fn (string $tone) => match ($tone) {
    'danger'  => ['bg' => 'bg-[#FDEDEC]', 'accent' => 'bg-[#C33939]', 'ink' => 'text-[#C33939]'],
    'warning' => ['bg' => 'bg-[#FFF8E8]', 'accent' => 'bg-[#946200]', 'ink' => 'text-[#946200]'],
    default   => ['bg' => 'bg-[#EDF0F9]', 'accent' => 'bg-[#0040A6]', 'ink' => 'text-[#0040A6]'],
};
?>

<div class="grid grid-cols-1 gap-4 sm:grid-cols-2 <?= $columns ?>">

    <?php foreach ($metrics as $metric): ?>

        <?php $colors = $palette($metric['tone'] ?? 'neutral'); ?>

        <div class="relative min-w-0 overflow-hidden rounded-2xl <?= $colors['bg'] ?> py-5 pl-6 pr-5">

            <span class="absolute inset-y-0 left-0 w-1.5 <?= $colors['accent'] ?>" aria-hidden="true"></span>

            <div class="flex items-start justify-between gap-3">

                <div class="flex min-w-0 flex-col gap-1">
                    <span class="text-xs font-bold uppercase tracking-wide text-[#646464]/80">
                        <?= esc($metric['label']) ?>
                    </span>

                    <span class="text-2xl font-black <?= $colors['ink'] ?>">
                        <?= esc($metric['value']) ?>
                    </span>
                </div>

                <?= icon($metric['icon'], 'h-6 w-6 ' . $colors['ink'] . ' opacity-70') ?>

            </div>

            <p class="mt-2 text-xs leading-snug text-[#646464] wrap-break-word">
                <?= esc($metric['detail']) ?>
            </p>

        </div>

    <?php endforeach; ?>

</div>
