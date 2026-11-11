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
    <?php // One scrolling row below md (scroll-x.js brings the current tab into view); wraps from md up. ?>
    <div data-scroll-x data-scroll-current class="-mb-px">
    <ul class="flex flex-nowrap gap-1 md:flex-wrap">
        <?php foreach ($tabs as $tab): ?>
            <?php $active = ! empty($tab['active']); ?>
            <li>
                <a
                    href="<?= esc($tab['href']) ?>"
                    class="inline-flex shrink-0 items-center gap-2 whitespace-nowrap border-b-2 px-4 py-2.5 pointer-coarse:min-h-11 text-sm font-semibold transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-brand/50 <?= $active ? 'border-brand text-brand' : 'border-transparent text-[#646464] hover:border-gray-300 hover:text-[#1A1C1E]' ?>"
                    <?= $active ? 'aria-current="page"' : '' ?>>
                    <?= esc($tab['label']) ?>
                    <?php if (isset($tab['count']) && $tab['count'] !== null): ?>
                        <span class="rounded-full bg-gray-100 px-2 py-0.5 text-[11px] font-bold text-[#646464]"><?= esc((string) $tab['count']) ?></span>
                    <?php endif; ?>
                </a>
            </li>
        <?php endforeach; ?>
    </ul>
    </div>
</nav>
