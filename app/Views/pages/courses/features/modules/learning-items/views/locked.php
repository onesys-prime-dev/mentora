<?php

/*
|--------------------------------------------------------------------------
| Shown instead of an item's content while it's locked behind prerequisite
| items. Expects $lock = ['message' => …, 'prerequisites' => [[id, title]]].
| In Modules the prerequisites are clickable (data-learning-item opens
| them); elsewhere ($lockLinks false) they're listed as plain text.
|--------------------------------------------------------------------------
*/

$lockLinks ??= true;

$prerequisites = $lock['prerequisites'] ?? [];
?>

<section class="flex flex-col items-center justify-center gap-5 rounded-2xl border border-dashed border-[#DBDBDB] bg-[#F9F9FC] px-8 py-16 text-center">

    <div class="flex h-16 w-16 items-center justify-center rounded-full bg-white shadow-sm">
        <?= icon('lock', 'h-8 w-8') ?>
    </div>

    <div class="flex flex-col gap-1.5">

        <span class="text-lg font-bold text-[#1A1C1E]">
            This item is locked
        </span>

        <span class="text-sm text-[#646464]">
            <?= esc($lock['message'] ?? 'Complete the required items first.') ?>
        </span>

    </div>

    <?php if ($prerequisites): ?>

        <div class="flex w-full max-w-md flex-col gap-2">

            <?php foreach ($prerequisites as $prerequisite): ?>

                <?php if ($lockLinks): ?>

                    <button
                        type="button"
                        class="learning-item-link flex items-center justify-between gap-3 rounded-xl border border-gray-200 bg-white px-4 py-3 text-left text-sm font-semibold text-[#0040A6] transition hover:border-[#0040A6]/40 hover:bg-[#0040A6]/5"
                        data-learning-item="<?= esc($prerequisite['id']) ?>">

                        <span class="truncate"><?= esc($prerequisite['title']) ?></span>

                        <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M9 18l6-6-6-6" />
                        </svg>

                    </button>

                <?php else: ?>

                    <span class="rounded-xl border border-gray-200 bg-white px-4 py-3 text-left text-sm font-semibold text-[#646464]">
                        <?= esc($prerequisite['title']) ?>
                    </span>

                <?php endif; ?>

            <?php endforeach; ?>

        </div>

        <?php if (!$lockLinks): ?>
            <span class="text-xs text-[#767676]">You'll find them in the Modules tab.</span>
        <?php endif; ?>

    <?php endif; ?>

</section>
