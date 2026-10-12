<?php

/*
|--------------------------------------------------------------------------
| Tabs — sub-views of one page, each its own address
|--------------------------------------------------------------------------
| Params: tabs (required): list of ['label', 'href', 'active' => bool,
| 'count' => int|null], ariaLabel.
|
| Real links, not script: the back button, bookmarks and "open in new tab" all
| behave. The current one is marked with aria-current.
|--------------------------------------------------------------------------
*/

$ariaLabel = $ariaLabel ?? 'Sections';
?>

<nav class="border-b border-gray-200" aria-label="<?= esc($ariaLabel) ?>" data-tabs>
    <ul class="-mb-px flex flex-wrap gap-1">
        <?php foreach ($tabs as $tab): ?>
            <?php $active = ! empty($tab['active']); ?>
            <li>
                <a
                    href="<?= esc($tab['href']) ?>"
                    class="inline-flex items-center gap-2 border-b-2 px-4 py-2.5 text-sm font-semibold transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/50 <?= $active ? 'border-brand text-brand' : 'border-transparent text-[#646464] hover:border-gray-300 hover:text-[#1A1C1E]' ?>"
                    <?= $active ? 'aria-current="page"' : '' ?>>
                    <?= esc($tab['label']) ?>
                    <?php if (isset($tab['count']) && $tab['count'] !== null): ?>
                        <span class="rounded-full bg-gray-100 px-2 py-0.5 text-[11px] font-bold text-[#646464]"><?= esc((string) $tab['count']) ?></span>
                    <?php endif; ?>
                </a>
            </li>
        <?php endforeach; ?>
    </ul>
</nav>
