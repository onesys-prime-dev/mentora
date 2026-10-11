<?php

/*
|--------------------------------------------------------------------------
| Data table — the one list table for management pages
|--------------------------------------------------------------------------
| Params:
|   caption      string (required)   describes the table to a screen reader
|   columns      list of ['key', 'label', 'sortable' => bool, 'class' => string]
|   rows         list of ['id' => scalar, 'cells' => [key => text | ['html' => '...']], 'actions' => html]
|                A plain string cell is escaped here. Markup must be passed as
|                ['html' => ...] by a caller that has already escaped it.
|   sort, dir    the current sort column and 'asc'|'desc'
|   sortUrl      callable(string $column): string, the link for a header
|   selectable   bool: a tick box per row and the bulk bar (shared/data-table.js)
|   bulk         html for the bulk bar (buttons inside a form[data-bulk-form])
|   nounOne, nounMany   "person"/"people": the words in "3 people selected"
|   emptyTitle, emptyText, emptyIcon   shown instead of the table when no rows
|
| Below the md breakpoint each row becomes a stacked card, so nothing needs a
| horizontal scroll on a phone.
|--------------------------------------------------------------------------
*/

$columns    = $columns ?? [];
$rows       = $rows ?? [];
$sort       = $sort ?? '';
$dir        = $dir ?? 'asc';
$sortUrl    = $sortUrl ?? null;
$selectable = $selectable ?? false;
$bulk       = $bulk ?? '';
$nounOne    = $nounOne ?? 'row';
$nounMany   = $nounMany ?? 'rows';
$emptyTitle = $emptyTitle ?? 'Nothing here yet';
$emptyText  = $emptyText ?? '';
$emptyIcon  = $emptyIcon ?? null;
$hasActions = false;

foreach ($rows as $row) {
    $hasActions = $hasActions || ! empty($row['actions']);
}

$th = 'px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-[#646464]';
?>

<?php if ($rows === []): ?>

    <div class="rounded-2xl bg-white/70">
        <?= view('components/empty-state', ['icon' => $emptyIcon, 'title' => $emptyTitle, 'text' => $emptyText], ['saveData' => false]) ?>
    </div>

<?php else: ?>

    <div class="overflow-hidden rounded-2xl bg-white shadow-sm" data-table>

        <?php if ($selectable): ?>
            <div class="flex flex-wrap items-center gap-3 border-b border-gray-100 bg-[#F2F7FF] px-4 py-3 text-sm" data-bulk-bar hidden>
                <span class="font-semibold text-[#002068]" data-selected-count data-noun-one="<?= esc($nounOne) ?>" data-noun-many="<?= esc($nounMany) ?>" aria-live="polite"></span>
                <?= $bulk ?>
            </div>
        <?php endif; ?>

        <table class="w-full text-sm">
            <caption class="sr-only"><?= esc($caption) ?></caption>

            <thead class="max-md:sr-only border-b border-gray-100 bg-gray-50/70">
                <tr>
                    <?php if ($selectable): ?>
                        <th class="w-10 px-4 py-3" scope="col">
                            <input type="checkbox" data-select-all class="h-4 w-4 rounded border-gray-300 text-[#0040A6] focus:ring-[#0040A6]/40" aria-label="Select every row on this page">
                        </th>
                    <?php endif; ?>

                    <?php foreach ($columns as $column): ?>
                        <?php
                        $isSorted = $sort === $column['key'];
                        $ariaSort = $isSorted ? ($dir === 'asc' ? 'ascending' : 'descending') : 'none';
                        $next     = $isSorted && $dir === 'asc' ? 'desc' : 'asc';
                        ?>
                        <th class="<?= $th ?> <?= esc($column['class'] ?? '') ?>" scope="col" <?= ! empty($column['sortable']) ? 'aria-sort="' . $ariaSort . '"' : '' ?>>
                            <?php if (! empty($column['sortable']) && $sortUrl !== null): ?>
                                <a class="inline-flex items-center gap-1 transition hover:text-[#0040A6] <?= $isSorted ? 'text-[#0040A6]' : '' ?>" href="<?= esc($sortUrl($column['key'], $next)) ?>">
                                    <?= esc($column['label']) ?><span aria-hidden="true"><?= $isSorted ? ($dir === 'asc' ? '▲' : '▼') : '' ?></span>
                                </a>
                            <?php else: ?>
                                <?= esc($column['label']) ?>
                            <?php endif; ?>
                        </th>
                    <?php endforeach; ?>

                    <?php if ($hasActions): ?>
                        <th class="<?= $th ?> text-right" scope="col"><span class="sr-only">Actions</span></th>
                    <?php endif; ?>
                </tr>
            </thead>

            <tbody class="divide-y divide-gray-100">
                <?php foreach ($rows as $row): ?>
                    <tr class="transition hover:bg-[#F2F7FF] max-md:block max-md:px-4 max-md:py-3">

                        <?php if ($selectable): ?>
                            <td class="w-10 px-4 py-3 max-md:inline-block max-md:px-0">
                                <input type="checkbox" data-row-select value="<?= esc((string) $row['id']) ?>" class="h-4 w-4 rounded border-gray-300 text-[#0040A6] focus:ring-[#0040A6]/40" aria-label="Select <?= esc((string) ($row['label'] ?? 'this row')) ?>">
                            </td>
                        <?php endif; ?>

                        <?php foreach ($columns as $column): ?>
                            <?php $cell = $row['cells'][$column['key']] ?? ''; ?>
                            <td class="px-4 py-3 align-middle text-[#1A1C1E] max-md:flex max-md:items-baseline max-md:justify-between max-md:gap-3 max-md:px-0 max-md:py-1 max-md:before:text-xs max-md:before:font-semibold max-md:before:text-[#646464] max-md:before:content-[attr(data-label)] <?= esc($column['class'] ?? '') ?>" data-label="<?= esc($column['label']) ?>">
                                <?= is_array($cell) && isset($cell['html']) ? $cell['html'] : esc((string) $cell) ?>
                            </td>
                        <?php endforeach; ?>

                        <?php if ($hasActions): ?>
                            <td class="px-4 py-3 text-right max-md:block max-md:px-0 max-md:pt-2 max-md:text-left">
                                <?= $row['actions'] ?? '' ?>
                            </td>
                        <?php endif; ?>

                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

    </div>

<?php endif; ?>
