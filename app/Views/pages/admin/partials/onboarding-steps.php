<?php
/**
 * The three onboarding steps, with the current one marked.
 *
 * @var int $obStep 1 (institution), 2 (plan and subscription) or 3 (administrator)
 */
$obLabels = ['Institution', 'Plan and subscription', 'Administrator'];
?>
<ol class="mt-4 flex flex-wrap items-center gap-x-4 gap-y-2 text-sm" aria-label="Onboarding steps" data-onboarding-steps>
    <?php foreach ($obLabels as $index => $obLabel): ?>
        <?php
        $number = $index + 1;
        $state  = $number < $obStep ? 'done' : ($number === $obStep ? 'current' : 'todo');
        $badge  = ['done' => 'bg-green-600 text-white', 'current' => 'bg-[#0040A6] text-white', 'todo' => 'bg-gray-200 text-gray-600'][$state];
        ?>
        <li class="flex items-center gap-2 <?= $state === 'todo' ? 'text-gray-500' : 'font-semibold text-gray-900' ?>" data-step="<?= $number ?>" data-state="<?= $state ?>" <?= $state === 'current' ? 'aria-current="step"' : '' ?>>
            <span class="flex h-6 w-6 items-center justify-center rounded-full text-xs font-bold <?= $badge ?>"><?= $state === 'done' ? '✓' : $number ?></span>
            <?= esc($obLabel) ?>
        </li>
    <?php endforeach; ?>
</ol>
