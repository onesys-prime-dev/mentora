<?php

/*
|--------------------------------------------------------------------------
| Stepper — where you are in a multi-step flow
|--------------------------------------------------------------------------
| Params: steps (required, list of labels), current (required, 1-based).
| Done steps show a tick, the current one is marked aria-current="step".
|--------------------------------------------------------------------------
*/
?>

<ol class="flex flex-wrap items-center gap-x-3 gap-y-2 text-sm" aria-label="Steps" data-stepper>
    <?php foreach ($steps as $index => $label): ?>
        <?php
        $number = $index + 1;
        $done   = $number < $current;
        $now    = $number === $current;
        ?>
        <li class="flex items-center gap-2" <?= $now ? 'aria-current="step"' : '' ?>>
            <span class="flex h-7 w-7 items-center justify-center rounded-full text-xs font-bold <?= $done ? 'bg-[#2E7D4F] text-white' : ($now ? 'bg-[#0040A6] text-white' : 'bg-gray-100 text-[#646464]') ?>">
                <?php if ($done): ?><?= icon('check', 'h-3.5 w-3.5 text-white') ?><span class="sr-only">Done: </span><?php else: ?><?= esc((string) $number) ?><?php endif; ?>
            </span>
            <span class="<?= $now ? 'font-semibold text-[#0040A6]' : 'text-[#646464]' ?>"><?= esc($label) ?></span>
            <?php if ($number < count($steps)): ?><span class="mx-1 hidden h-px w-8 bg-gray-300 sm:block" aria-hidden="true"></span><?php endif; ?>
        </li>
    <?php endforeach; ?>
</ol>
