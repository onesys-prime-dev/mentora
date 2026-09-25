<?php

/*
|--------------------------------------------------------------------------
| Key metric cards (built by AssessmentOverviewService):
|   student    → Due This Week · Overdue · Completed
|   instructor → To Grade · Due This Week · Submission Rate · Needs Attention
| Each: label, icon, tone (neutral|warning|danger), value, detail.
|--------------------------------------------------------------------------
*/

$metrics ??= [];

$columns = count($metrics) >= 4 ? 'lg:grid-cols-4' : 'lg:grid-cols-3';

$bubble = static fn (string $tone) => match ($tone) {
    'danger' => 'bg-[#FFDAD6]',
    'warning' => 'bg-[#FFF1D6]',
    default => 'bg-[#EEEEEE]',
};
?>

<div class="grid grid-cols-1 gap-6 sm:grid-cols-2 <?= $columns ?>">

    <?php foreach ($metrics as $metric): ?>

        <div class="flex min-w-0 items-center gap-4 rounded-2xl border border-[#A9A9A9]/50 p-6">

            <div class="flex h-12 w-12 shrink-0 items-center justify-center rounded-full <?= $bubble($metric['tone'] ?? 'neutral') ?>">
                <?= icon($metric['icon'], 'h-6 w-6') ?>
            </div>

            <div class="flex min-w-0 flex-col gap-0.5">

                <span class="text-sm font-bold uppercase text-[#878787]">
                    <?= esc($metric['label']) ?>
                </span>

                <span class="text-xl font-bold text-[#0040A6]">
                    <?= esc($metric['value']) ?>
                </span>

                <span class="truncate text-xs text-[#878787]" title="<?= esc($metric['detail']) ?>">
                    <?= esc($metric['detail']) ?>
                </span>

            </div>

        </div>

    <?php endforeach; ?>

</div>
