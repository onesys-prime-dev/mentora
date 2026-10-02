<?php

/*
|--------------------------------------------------------------------------
| Key metric cards — Overview's own copy
|--------------------------------------------------------------------------
| Same tinted-panel-with-accent-bar look as the Assessments tab's cards
| (copied on request, kept as a separate file so either can keep changing
| independently) — but Overview's own data: AssessmentOverviewService's
| top-line urgency numbers, unchanged from before.
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
