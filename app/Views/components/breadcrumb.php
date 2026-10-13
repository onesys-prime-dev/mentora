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
|--------------------------------------------------------------------------
*/

$crumbs ??= [];
$last = count($crumbs) - 1;
?>

<nav aria-label="Breadcrumb">

    <ol class="flex flex-wrap items-center gap-x-2 text-sm">

        <?php foreach ($crumbs as $index => $crumb): ?>

            <li class="flex items-center gap-2">

                <?php if ($index > 0): ?>
                    <svg class="h-5 w-4 shrink-0 text-[#767676]" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <path d="M9 18l6-6-6-6" />
                    </svg>
                <?php endif; ?>

                <?php if ($index === $last): ?>

                    <span class="font-medium text-[#1A1C1E]" aria-current="page"><?= esc($crumb['label']) ?></span>

                <?php elseif (!empty($crumb['attrs'])): ?>

                    <button
                        type="button"
                        class="rounded font-medium text-[#646464] transition hover:text-brand focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/50"
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
