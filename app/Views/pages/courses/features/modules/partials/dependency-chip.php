<?php

/*
|--------------------------------------------------------------------------
| What a module or learning unit waits on.
|
| Params: lockedBy (a student's list of {id, title} still to complete; empty
| when it is open), labels (an instructor's titles it is set to wait for)
|--------------------------------------------------------------------------
*/

$lockedBy = $lockedBy ?? [];
$labels   = $labels ?? [];
?>
<?php if ($lockedBy !== []): ?>
    <?php $names = implode(', ', array_map(static fn (array $p) => '“' . $p['title'] . '”', $lockedBy)); ?>
    <span
        title="Complete <?= esc($names) ?> to unlock this."
        class="inline-flex shrink-0 items-center gap-1 rounded-full bg-[#FFF1D6] px-2 py-0.5 text-[10px] font-medium text-[#946200]"
        data-dependency-chip="locked">
        <?= icon('lock', 'h-3 w-3 text-current') ?>
        Locked · after <?= esc(implode(', ', array_column($lockedBy, 'title'))) ?>
    </span>
<?php elseif ($labels !== []): ?>
    <span
        title="Students must complete: <?= esc(implode(', ', $labels)) ?>"
        class="inline-flex shrink-0 items-center gap-1 rounded-full bg-gray-100 px-2 py-0.5 text-[10px] font-medium text-gray-500"
        data-dependency-chip="waits">
        <?= icon('lock', 'h-3 w-3 text-current') ?>
        After <?= esc(implode(', ', $labels)) ?>
    </span>
<?php endif; ?>
