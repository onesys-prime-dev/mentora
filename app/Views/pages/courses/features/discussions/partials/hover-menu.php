<?php
/*
|--------------------------------------------------------------------------
| Hover overlay: quick reactions + a ⋮ more-options menu, same pattern as
| an announcement card. The parent needs `group relative`.
| Params: own (emoji|null), items (list of [attrs, label, labelAttr?, tone?]).
|--------------------------------------------------------------------------
*/

$quick = ['👍' => 'Like', '❤️' => 'Love', '😂' => 'Haha', '😮' => 'Wow'];
$items = $items ?? [];
?>

<!-- hover overlay: quick reacts + more options -->
<div class="absolute right-4 top-4 z-20 flex items-center gap-1 rounded-full border border-gray-200 bg-white px-1.5 py-1 opacity-0 shadow-md transition-opacity duration-150 focus-within:opacity-100 group-hover:opacity-100">

    <?php foreach ($quick as $emoji => $label): ?>
        <button
            type="button"
            data-react="<?= esc($emoji) ?>"
            title="<?= esc($label) ?>"
            aria-label="<?= esc($label) ?>"
            aria-pressed="<?= $own === $emoji ? 'true' : 'false' ?>"
            class="flex h-8 w-8 items-center justify-center rounded-full text-lg transition hover:scale-110 hover:bg-gray-100 <?= $own === $emoji ? 'bg-[#0040A6]/10' : '' ?>">
            <?= $emoji ?>
        </button>
    <?php endforeach; ?>

    <?php if ($items !== []): ?>

        <span class="mx-1 h-5 w-px bg-gray-200"></span>

        <div class="relative">

            <button type="button" data-more-options-toggle title="More options" aria-label="More options" class="flex h-8 w-8 items-center justify-center rounded-full text-gray-500 transition hover:bg-gray-100">
                <svg class="h-5 w-5" xmlns="http://www.w3.org/2000/svg" fill="currentColor" viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="5" r="1.8"/><circle cx="12" cy="12" r="1.8"/><circle cx="12" cy="19" r="1.8"/></svg>
            </button>

            <div class="absolute right-0 top-9 z-30 hidden w-44 overflow-hidden rounded-xl border border-gray-200 bg-white py-1 shadow-xl" data-more-options-panel data-action-menu-panel>

                <?php foreach ($items as $item): ?>
                    <button
                        type="button"
                        <?= $item['attrs'] ?>
                        class="flex w-full items-center gap-2 px-4 py-2 text-left text-sm <?= ($item['tone'] ?? '') === 'danger' ? 'text-[#C33939] hover:bg-[#FDEDEC]' : 'text-gray-700 hover:bg-gray-50' ?>">
                        <span <?= $item['labelAttr'] ?? '' ?>><?= esc($item['label']) ?></span>
                    </button>
                <?php endforeach; ?>

            </div>

        </div>

    <?php endif; ?>

</div>
