<?php

/*
|--------------------------------------------------------------------------
| Usage bar — a value against a limit
|--------------------------------------------------------------------------
| Params: label (required), used (required), limit (null = unlimited), unitLabel
| ("GB"). It is not called `unit`: a page's own data can carry that name and
| view data is shared, which would leak in here.
| Green below 90%, amber from 90%, red at 100%. The words say it as well, so
| colour is never the only signal.
|--------------------------------------------------------------------------
*/

$unit  = is_string($unitLabel ?? null) ? $unitLabel : '';
$limit = $limit ?? null;

$percent = $limit === null || (float) $limit <= 0 ? 0 : min(100, (int) round(((float) $used / (float) $limit) * 100));

[$bar, $word, $ink] = match (true) {
    $limit === null    => ['bg-gray-300', 'Unlimited', 'text-[#646464]'],
    $percent >= 100    => ['bg-[#C33939]', 'Full', 'text-[#C33939]'],
    $percent >= 90     => ['bg-[#946200]', 'Nearly full', 'text-[#946200]'],
    default            => ['bg-[#2E7D4F]', 'OK', 'text-[#2E7D4F]'],
};

$amount = number_format((float) $used) . ($unit !== '' ? ' ' . $unit : '') . ($limit === null ? '' : ' of ' . number_format((float) $limit) . ($unit !== '' ? ' ' . $unit : ''));
?>

<div class="flex flex-col gap-1.5" data-usage="<?= esc($word) ?>">
    <div class="flex items-baseline justify-between gap-3 text-sm">
        <span class="font-semibold text-[#1A1C1E]"><?= esc($label) ?></span>
        <span class="text-xs <?= $ink ?>"><?= esc($word) ?> · <?= esc($amount) ?></span>
    </div>
    <div class="h-2 overflow-hidden rounded-full bg-gray-100" role="progressbar" aria-label="<?= esc($label) ?>" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?= $percent ?>">
        <div class="h-full rounded-full <?= $bar ?>" style="width: <?= $percent ?>%"></div>
    </div>
</div>
