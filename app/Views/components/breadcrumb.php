<?php

/*
|--------------------------------------------------------------------------
| Course Workspace breadcrumb — the one trail used by Modules, Assessments
| and Resources
|--------------------------------------------------------------------------
| Param: crumbs — list of ['label' => string, 'attrs' => string|null].
| A crumb with `attrs` is a button carrying those (already-escaped) data
| attributes, so each feature's JS keeps its own hooks; a crumb without
| them is plain text. The last crumb is the current page (aria-current).
|
| Param: sticky (default true) — from lg up the trail stays at the top of
| the course card while the page scrolls, so it is always one click away.
| The negative bottom margin keeps the spacing below it unchanged; the
| padding is the white strip content scrolls under. Pass false where the
| trail already sits in a pinned bar (the editors) or its wrapper pins.
|--------------------------------------------------------------------------
*/

$crumbs ??= [];
$sticky ??= true;
$last = count($crumbs) - 1;
?>

<nav aria-label="Breadcrumb" class="group/crumbs<?= $sticky ? ' sticky-bar-top lg:-mb-4 lg:pb-4' : '' ?>" <?= $sticky ? 'data-sticky-breadcrumb' : '' ?>>

    <?php // Below sm each crumb is still rendered once: earlier ones hide behind an ellipsis whose <details> reveals them via :has() (no script). The group is named, so an open <details> in an outer `group` (a data-menu dropdown) cannot reveal them. ?>
    <ol class="flex flex-nowrap items-center gap-x-2 text-sm sm:flex-wrap">

        <?php if ($last > 0): ?>
            <li class="flex items-center sm:hidden">
                <details>
                    <summary class="cursor-pointer list-none rounded px-1 font-medium text-[#646464] pointer-coarse:flex pointer-coarse:min-h-11 pointer-coarse:min-w-11 pointer-coarse:items-center pointer-coarse:justify-center [&::-webkit-details-marker]:hidden" aria-label="Show the full path">&hellip;</summary>
                </details>
            </li>
        <?php endif; ?>

        <?php foreach ($crumbs as $index => $crumb): ?>

            <li class="<?= $index < $last ? 'max-sm:hidden max-sm:group-has-[details[open]]/crumbs:flex ' : '' ?>flex min-w-0 items-center gap-2">

                <?php if ($index > 0): ?>
                    <svg class="h-5 w-4 shrink-0 text-[#767676]" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <path d="M9 18l6-6-6-6" />
                    </svg>
                <?php endif; ?>

                <?php if ($index === $last): ?>

                    <span class="truncate font-medium text-[#1A1C1E]" aria-current="page"><?= esc($crumb['label']) ?></span>

                <?php elseif (!empty($crumb['attrs'])): ?>

                    <button
                        type="button"
                        class="rounded font-medium text-[#646464] transition hover:text-brand focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/50 pointer-coarse:min-h-11"
                        <?= $crumb['attrs'] ?>>
                        <?= esc($crumb['label']) ?>
                    </button>

                <?php else: ?>

                    <span class="text-[#646464]"><?= esc($crumb['label']) ?></span>

                <?php endif; ?>

            </li>

        <?php endforeach; ?>

    </ol>

</nav>
