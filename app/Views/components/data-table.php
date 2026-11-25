<?php

/*
|--------------------------------------------------------------------------
| Data table — the one list table for management pages
|--------------------------------------------------------------------------
| Params:
|   caption      string (required)   describes the table to a screen reader
|   columns      list of ['key', 'label', 'sortable' => bool, 'class' => string,
|                'priority' => 1|2|3, 'role' => 'primary'|'secondary'|'status'|'avatar']
|                priority: 1 (default) always shows from md, 2 from lg, 3 from xl. The
|                first column is always 1, so the table never loses its first column.
|                role: where the column goes in the phone row. With no role set
|                anywhere, the first column is primary, the second secondary, and a
|                column keyed "status" (or holding a status-badge) is the status. Only
|                the first column of each role counts.
|   rows         list of ['id' => scalar, 'cells' => [key => text | ['html' => '...']], 'actions' => html,
|                'label' => string, 'group' => string]
|                A plain string cell is escaped here. Markup must be passed as
|                ['html' => ...] by a caller that has already escaped it.
|                group: consecutive rows of one group share a phone panel under a
|                heading with their count (phones only; the table is unchanged).
|   sort, dir    the current sort column and 'asc'|'desc'
|   sortUrl      callable(string $column): string, the link for a header
|   selectable   bool: a tick box per row and the bulk bar (shared/data-table.js)
|   bulk         html for the bulk bar (buttons inside a form[data-bulk-form])
|   nounOne, nounMany   "person"/"people": the words in "3 people selected"
|   emptyTitle, emptyText, emptyIcon   shown instead of the table when no rows
|   fill         bool: under the shell lock (lock:) the table fills the height its
|                parent gives it and scrolls on its own (the parent must be a flex
|                column of a set height); below lg the page scrolls and the heading
|                row pins under the app header; between lg and the lock it does not pin
|   phoneRows    bool (default true): below md each row is a thin list row (tick box,
|                avatar or initials, the primary text over the status and secondary
|                text, the actions) in a white panel per group. false keeps the older
|                stacked label/value card per row. Phone rows also give the table
|                explicit ARIA table roles, since changing display drops them in WebKit.
|   rows[].person the AvatarData::forViewer() entry ['person' => ..., 'interactive' => bool] for the row's
|                person: an avatar (components/avatar, sm) starts the primary cell from md and the phone row.
|                Rows without it render as before. Decorative (aria-hidden) unless interactive.
|   avatar       bool (default true): false leaves the avatar out of the phone row.
|                Initials (first letters of the first and last words) are shown only
|                when the primary text starts with a letter.
|   id           string: names the row sheet "<id>-actions" (default: a per-request counter)
|
| Row sheet (phone rows only, shared/row-actions.js): when rows have actions or some
| column has no phone role, each phone row ends in a ⋯ that opens one bottom sheet per
| table. The sheet lists the row's role-less columns (from a <template>, so nothing in
| them is duplicated live) and then the row's actions, moved in while it is open. A
| destructive action (data-destructive, or red text) sits under a divider.
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
$fill       = $fill ?? false;
$phoneRows  = $phoneRows ?? true;
$avatar     = $avatar ?? true;
$hasActions = false;

foreach ($rows as $row) {
    $hasActions = $hasActions || ! empty($row['actions']);
}

$cellHtml = static fn ($cell): string => is_array($cell) && isset($cell['html']) ? (string) $cell['html'] : esc((string) $cell);

// The phone row's roles, as role => column key.
$roles = ['primary' => null, 'secondary' => null, 'status' => null, 'avatar' => null];

if (array_filter(array_column($columns, 'role')) !== []) {
    foreach ($columns as $column) {
        $role = $column['role'] ?? null;

        if ($role !== null && array_key_exists($role, $roles) && $roles[$role] === null) {
            $roles[$role] = $column['key'];
        }
    }
} else {
    foreach (array_slice($columns, 1) as $column) {
        $badge = false;

        foreach ($rows as $row) {
            $cell = $row['cells'][$column['key']] ?? '';

            if ($cell !== '') {
                $badge = str_contains($cellHtml($cell), 'data-status-badge');

                break;
            }
        }

        if ($column['key'] === 'status' || $badge) {
            $roles['status'] = $column['key'];

            break;
        }
    }

    if (isset($columns[1]) && $columns[1]['key'] !== $roles['status']) {
        $roles['secondary'] = $columns[1]['key'];
    }
}

// Every phone row needs a title: without a primary, the first column is it.
if ($roles['primary'] === null && $columns !== []) {
    $roles = array_map(static fn ($key) => $key === $columns[0]['key'] ? null : $key, $roles);
    $roles['primary'] = $columns[0]['key'];
}

if (! $avatar) {
    $roles['avatar'] = null;
}

$roleOf = $phoneRows ? array_flip(array_filter($roles)) : [];

// The columns a phone row leaves out: the sheet lists them, so nothing is out of reach on a phone.
$extra = $phoneRows ? array_values(array_filter($columns, static fn ($column) => ! isset($roleOf[$column['key']]))) : [];
$sheet = $phoneRows && ($hasActions || $extra !== []);

$GLOBALS['mentoraDataTableSeq'] = ((int) ($GLOBALS['mentoraDataTableSeq'] ?? 0)) + 1;
$sheetId = ($id ?? 'data-table-' . $GLOBALS['mentoraDataTableSeq']) . '-actions';

// Lower priorities drop out between md and their breakpoint; the first column never does.
$hide = static function (int $i, array $column): string {
    $priority = $i === 0 ? 1 : (int) ($column['priority'] ?? 1);

    return match ($priority) {
        2       => ' md:max-lg:hidden',
        3       => ' md:max-xl:hidden',
        default => '',
    };
};

// Consecutive rows of one group form one panel.
$groups = [];

foreach ($rows as $row) {
    $group = $row['group'] ?? null;
    $last  = array_key_last($groups);

    if ($last !== null && $groups[$last]['name'] === $group) {
        $groups[$last]['rows'][] = $row;
    } else {
        $groups[] = ['name' => $group, 'rows' => [$row]];
    }
}

$span = count($columns) + ($selectable ? 1 : 0) + ($hasActions ? 1 : 0);

$th = 'px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-[#646464]';

// A pinned heading row needs a solid background, or the rows show through it. Below lg the window
// scrolls, so it parks under the app header. From lg the wrapper is the scroller (as on main) and it
// parks at its top-0: under the lock the wrapper has a set height and scrolls, so the heading pins;
// between lg and the lock the wrapper grows to its rows and does not scroll, so the heading stays put
// while the window scrolls (the accepted pre-phase-3 behaviour).
$pin = $fill ? ' sticky top-0 max-lg:top-[var(--app-header-h,0px)] z-10 bg-gray-50' : '';

// Explicit table roles while phone rows can restyle the table (WebKit drops them on a display change).
$aria = static fn (string $role): string => $phoneRows ? ' role="' . $role . '"' : '';

// overflow-clip, not hidden: below lg the wrapper must not become a scroll container, or the heading could not pin
// to the window. From lg it scrolls (as before phase 3), so a row menu (people.php's Actions) opening past the
// last row can be scrolled to instead of being clipped.
$wrap = $fill
    ? 'overflow-clip rounded-2xl bg-white shadow-sm lg:overflow-y-auto lock:min-h-0 lock:flex-1 [scrollbar-width:none] [&::-webkit-scrollbar]:hidden'
    : 'overflow-hidden rounded-2xl bg-white shadow-sm';
?>

<?php if ($rows === []): ?>

    <div class="rounded-2xl bg-white/70">
        <?= view('components/empty-state', ['icon' => $emptyIcon, 'title' => $emptyTitle, 'text' => $emptyText], ['saveData' => false]) ?>
    </div>

<?php else: ?>

    <div class="<?= $wrap ?>"<?= $fill ? ' tabindex="0" role="region" aria-label="' . esc($caption, 'attr') . '"' : '' ?> data-table<?= $phoneRows ? ' data-phone-rows' : '' ?>>

        <?php if ($selectable): ?>
            <div class="flex flex-wrap items-center gap-3 border-b border-gray-100 bg-brand-tint px-4 py-3 text-sm<?= $phoneRows ? ' max-md:mb-3 max-md:rounded-2xl max-md:border-b-0' : '' ?>" data-bulk-bar hidden>
                <span class="font-semibold text-brand-dark" data-selected-count data-noun-one="<?= esc($nounOne) ?>" data-noun-many="<?= esc($nounMany) ?>" aria-live="polite"></span>
                <?= $bulk ?>
            </div>
        <?php endif; ?>

        <table class="w-full text-sm<?= $phoneRows ? ' max-md:block' : '' ?>"<?= $aria('table') ?>>
            <caption class="sr-only"><?= esc($caption) ?></caption>

            <?php // Below md the heading row is for screen readers, but a keyboard can still reach its sort links: it shows while one has focus, as a skip link does. ?>
            <thead class="max-md:sr-only max-md:focus-within:not-sr-only max-md:focus-within:block border-b border-gray-100 bg-gray-50/70"<?= $aria('rowgroup') ?>>
                <tr<?= $aria('row') ?><?= $phoneRows ? ' class="max-md:flex max-md:flex-wrap"' : '' ?>>
                    <?php if ($selectable): ?>
                        <th class="w-10 px-4 py-3<?= $pin ?>" scope="col"<?= $aria('columnheader') ?>>
                            <label class="inline-flex items-center justify-center pointer-coarse:min-h-11 pointer-coarse:min-w-11">
                                <input type="checkbox" data-select-all class="h-4 w-4 rounded border-gray-300 text-brand focus:ring-brand/40" aria-label="Select every row on this page">
                            </label>
                        </th>
                    <?php endif; ?>

                    <?php foreach ($columns as $i => $column): ?>
                        <?php
                        $isSorted = $sort === $column['key'];
                        $ariaSort = $isSorted ? ($dir === 'asc' ? 'ascending' : 'descending') : 'none';
                        $next     = $isSorted && $dir === 'asc' ? 'desc' : 'asc';
                        ?>
                        <th class="<?= $th ?><?= $pin ?><?= $hide($i, $column) ?> <?= esc($column['class'] ?? '') ?>" scope="col"<?= $aria('columnheader') ?> <?= ! empty($column['sortable']) ? 'aria-sort="' . $ariaSort . '"' : '' ?>>
                            <?php if (! empty($column['sortable']) && $sortUrl !== null): ?>
                                <?php // 44px on touch only (every role's tables): a mouse keeps the slim heading row. ?>
                                <a class="inline-flex items-center gap-1 transition hover:text-brand pointer-coarse:min-h-11 pointer-coarse:min-w-11 <?= $isSorted ? 'text-brand' : '' ?>" href="<?= esc($sortUrl($column['key'], $next)) ?>">
                                    <?= esc($column['label']) ?><span aria-hidden="true"><?= $isSorted ? ($dir === 'asc' ? '▲' : '▼') : '' ?></span>
                                </a>
                            <?php else: ?>
                                <?= esc($column['label']) ?>
                            <?php endif; ?>
                        </th>
                    <?php endforeach; ?>

                    <?php if ($hasActions): ?>
                        <th class="<?= $th ?><?= $pin ?> text-right" scope="col"<?= $aria('columnheader') ?>><span class="sr-only">Actions</span></th>
                    <?php endif; ?>
                </tr>
            </thead>

            <?php foreach ($groups as $g => $group): ?>
                <tbody class="divide-y divide-gray-100 bg-white<?= $g > 0 ? ' border-t border-gray-100' : '' ?><?= $phoneRows ? ' max-md:block max-md:overflow-hidden max-md:rounded-2xl max-md:shadow-sm' . ($g > 0 ? ' max-md:mt-3 max-md:border-t-0' : '') : '' ?>"<?= $aria('rowgroup') ?> data-panel<?= $group['name'] !== null ? ' data-group="' . esc($group['name']) . '"' : '' ?>>

                    <?php if ($phoneRows && $group['name'] !== null): ?>
                        <tr class="block md:hidden" role="row" data-group-heading>
                            <th class="block px-4 pb-1 pt-3 text-left text-[11px] font-semibold uppercase tracking-wide text-gray-500" colspan="<?= $span ?>" scope="rowgroup" role="rowheader">
                                <span data-group-name><?= esc($group['name']) ?></span> <span aria-hidden="true">·</span> <span data-group-count><?= count($group['rows']) ?></span>
                            </th>
                        </tr>
                    <?php endif; ?>

                    <?php foreach ($group['rows'] as $row): ?>
                        <tr class="transition hover:bg-brand-tint<?= $phoneRows ? '' : ' max-md:block max-md:px-4 max-md:py-3' ?>"<?= $phoneRows ? ' role="row" data-row' : '' ?>>

                            <?php if ($selectable): ?>
                                <td class="w-10 px-4 py-3 <?= $phoneRows ? 'max-md:w-auto max-md:p-0 max-md:pr-3' : 'max-md:inline-block max-md:px-0' ?>"<?= $phoneRows ? ' role="cell" data-role="select"' : '' ?>>
                                    <?php // The label gives the small box a finger-sized hit area. ?>
                                    <label class="inline-flex items-center justify-center pointer-coarse:min-h-11 pointer-coarse:min-w-11">
                                        <input type="checkbox" data-row-select value="<?= esc((string) $row['id']) ?>" class="h-4 w-4 rounded border-gray-300 text-brand focus:ring-brand/40" aria-label="Select <?= esc((string) ($row['label'] ?? 'this row')) ?>">
                                    </label>
                                </td>
                            <?php endif; ?>

                            <?php
                            // Initials only for a name-like primary text: the first letters of its first and last words.
                            $title    = trim(html_entity_decode(strip_tags($cellHtml($row['cells'][$roles['primary']] ?? '')), ENT_QUOTES | ENT_HTML5));
                            $title    = $title !== '' ? $title : trim((string) ($row['label'] ?? ''));
                            $words    = preg_split('/\s+/u', $title, -1, PREG_SPLIT_NO_EMPTY) ?: [];
                            $initials = count($words) > 1 ? mb_substr($words[0], 0, 1) . mb_substr($words[count($words) - 1], 0, 1) : mb_substr($title, 0, 2);
                            $initials = preg_match('/^\p{L}/u', $title) === 1 ? mb_strtoupper($initials) : '';
                            ?>
                            <?php $person = $row['person'] ?? null; ?>
                            <?php if ($person !== null && $phoneRows): ?>
                                <td class="md:hidden" role="cell" data-role="avatar">
                                    <?= view('components/avatar', ['person' => $person['person'], 'size' => 'sm', 'interactive' => $person['interactive'], 'decorative' => true], ['saveData' => false]) ?>
                                </td>
                            <?php elseif ($phoneRows && $avatar && $roles['avatar'] === null && $initials !== ''): ?>
                                <td class="md:hidden" role="cell" data-role="avatar" aria-hidden="true">
                                    <span class="flex h-9 w-9 items-center justify-center rounded-full bg-brand-tint text-xs font-bold text-brand-dark"><?= esc($initials) ?></span>
                                </td>
                            <?php endif; ?>

                            <?php foreach ($columns as $i => $column): ?>
                                <?php
                                $cell  = $row['cells'][$column['key']] ?? '';
                                $role  = $roleOf[$column['key']] ?? null;
                                $phone = match (true) {
                                    ! $phoneRows        => ' max-md:flex max-md:items-baseline max-md:justify-between max-md:gap-3 max-md:px-0 max-md:py-1 max-md:before:text-xs max-md:before:font-semibold max-md:before:text-[#646464] max-md:before:content-[attr(data-label)]',
                                    $role === null      => ' max-md:hidden',
                                    $role === 'primary' => ' max-md:truncate max-md:p-0 max-md:font-medium',
                                    $role === 'secondary' => ' max-md:truncate max-md:p-0 max-md:text-xs max-md:text-[#646464]',
                                    default             => ' max-md:p-0',
                                };
                                ?>
                                <td class="px-4 py-3 align-middle text-[#1A1C1E]<?= $phone ?><?= $hide($i, $column) ?> <?= esc($column['class'] ?? '') ?>" data-label="<?= esc($column['label']) ?>"<?= $aria('cell') ?><?= $role !== null ? ' data-role="' . $role . '"' : '' ?>>
                                    <?php if ($person !== null && $column['key'] === $roles['primary']): ?>
                                        <span class="md:flex md:items-center md:gap-3"><span class="max-md:hidden"><?= view('components/avatar', ['person' => $person['person'], 'size' => 'sm', 'interactive' => $person['interactive'], 'decorative' => true], ['saveData' => false]) ?></span><span class="min-w-0"><?= $cellHtml($cell) ?></span></span>
                                    <?php else: ?>
                                        <?= $cellHtml($cell) ?>
                                    <?php endif; ?>
                                </td>
                            <?php endforeach; ?>

                            <?php if ($hasActions): ?>
                                <td class="px-4 py-3 text-right <?= $phoneRows ? 'max-md:hidden' : 'max-md:block max-md:px-0 max-md:pt-2 max-md:text-left' ?>"<?= $phoneRows ? ' role="cell" data-role="actions"' : '' ?>>
                                    <?= $row['actions'] ?? '' ?>
                                </td>
                            <?php endif; ?>

                            <?php if ($sheet && (! empty($row['actions']) || $extra !== [])): ?>
                                <?php $name = $title !== '' ? $title : 'Row'; ?>
                                <td class="pl-3 md:hidden" role="cell" data-role="more">
                                    <button type="button" data-row-actions-open aria-haspopup="dialog" aria-controls="<?= esc($sheetId) ?>" aria-label="<?= esc((! empty($row['actions']) ? 'Actions for ' : 'Details for ') . $name) ?>" data-row-label="<?= esc((string) ($row['label'] ?? '')) ?>" class="flex h-11 w-11 items-center justify-center rounded-full text-lg font-bold text-[#646464] transition hover:bg-brand-tint focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/50"><span aria-hidden="true">⋯</span></button>
                                    <?php if ($extra !== []): ?>
                                        <template data-row-fields>
                                            <?php foreach ($extra as $column): ?>
                                                <?php $value = $cellHtml($row['cells'][$column['key']] ?? ''); ?>
                                                <div class="flex items-baseline justify-between gap-4 py-2.5">
                                                    <dt class="shrink-0 text-xs font-semibold text-[#646464]"><?= esc($column['label']) ?></dt>
                                                    <dd class="min-w-0 break-words text-right text-[#1A1C1E]"><?= trim($value) !== '' ? $value : '—' ?></dd>
                                                </div>
                                            <?php endforeach; ?>
                                        </template>
                                    <?php endif; ?>
                                </td>
                            <?php endif; ?>

                        </tr>
                    <?php endforeach; ?>
                </tbody>
            <?php endforeach; ?>
        </table>

        <?php if ($sheet): ?>
            <?= view('components/dialog', [
                'id'      => $sheetId,
                'title'   => 'Row',
                'variant' => 'sheet',
                'body'    => '<dl class="flex flex-col divide-y divide-gray-100 empty:hidden" data-row-actions-fields></dl>'
                    . '<div class="flex flex-col items-start gap-1 empty:hidden" data-row-actions-slot></div>',
            ], ['saveData' => false]) ?>
        <?php endif; ?>

    </div>

<?php endif; ?>
