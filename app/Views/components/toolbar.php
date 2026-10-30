<?php

/*
|--------------------------------------------------------------------------
| Toolbar — the filter bar above a list
|--------------------------------------------------------------------------
| Params: action (required), fields (required), filtered (bool, shows Clear),
| clearUrl, hidden (name => value, kept when the filters are applied, such as
| the tab the list is on), surface ('raised' by default: a white card with a
| shadow, for a page on the plain background; 'tinted': a soft brand tint with a
| border, for a toolbar that sits on a white panel, where white would not show).
|
| fields: list of
|   ['type' => 'search', 'name' => 'q', 'label' => 'Search', 'placeholder' => '...', 'value' => '', 'span' => 'lg:col-span-2']
|   ['type' => 'select', 'name' => 'role', 'label' => 'Role', 'options' => ['' => 'Any role', 'student' => 'Student'], 'value' => '']
|   ['type' => 'date', 'name' => 'from', 'label' => 'From', 'value' => '2026-08-01']
|
| A field may carry 'attrs' (name => value, values escaped here), for a script that
| works on the control, such as a dependent select.
|
| inline (true or '2xl'): lay the fields and the Apply button out in one row from
| the xl (or 2xl) breakpoint, instead of four columns, so the bar is one line tall.
| A field with 'span' => 'lg:col-span-2' gets twice the width of the others. Below the
| breakpoint the bar is laid out as usual.
|
| Filters travel in the address (GET), so a filtered view can be bookmarked or
| shared, and every sort and paging link keeps them.
|--------------------------------------------------------------------------
*/

$filtered = $filtered ?? false;
$clearUrl = $clearUrl ?? $action;
$hidden   = $hidden ?? [];
$inline   = $inline ?? false;
// Whole class names, so the stylesheet picks them up.
$columns  = match (true) {
    $inline === '2xl' => 'lg:grid-cols-4 2xl:[grid-template-columns:var(--toolbar-cols)]',
    $inline !== false => 'lg:grid-cols-4 xl:[grid-template-columns:var(--toolbar-cols)]',
    default           => 'lg:grid-cols-4',
};
$span     = match (true) {
    $inline === '2xl' => 'lg:col-span-4 2xl:col-span-1',
    $inline !== false => 'lg:col-span-4 xl:col-span-1',
    default           => 'lg:col-span-4',
};
$wide     = $inline === '2xl' ? '2xl:col-span-1' : 'xl:col-span-1';
$tracks   = implode(' ', array_map(static fn (array $f): string => str_contains((string) ($f['span'] ?? ''), 'col-span-2') ? 'minmax(0, 2fr)' : 'minmax(0, 1fr)', $fields));
$surface  = ($surface ?? 'raised') === 'tinted' ? 'border border-brand/20 bg-brand-tint' : 'bg-white shadow-sm';

$input = 'w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm focus:border-brand/60 focus:outline-none focus:ring-2 focus:ring-brand/40';
?>

<form method="get" action="<?= esc($action) ?>" role="search" class="grid gap-3 rounded-2xl <?= $surface ?> p-4 sm:grid-cols-2 <?= $columns ?>"<?= $inline !== false ? ' style="--toolbar-cols: ' . esc($tracks) . ' auto"' : '' ?> data-toolbar>

    <?php foreach ($hidden as $hiddenName => $hiddenValue): ?>
        <input type="hidden" name="<?= esc((string) $hiddenName) ?>" value="<?= esc((string) $hiddenValue) ?>">
    <?php endforeach; ?>

    <?php foreach ($fields as $field): ?>
        <label class="flex min-w-0 flex-col gap-1 text-xs font-medium text-gray-600 <?= esc($field['span'] ?? '') ?><?= $inline !== false && str_contains((string) ($field['span'] ?? ''), 'col-span-2') ? ' ' . $wide : '' ?>">
            <?= esc($field['label']) ?>
            <?php if (($field['type'] ?? 'search') === 'select'): ?>
                <select class="<?= $input ?>" name="<?= esc($field['name']) ?>"<?php foreach (($field['attrs'] ?? []) as $attr => $attrValue): ?> <?= esc((string) $attr) ?>="<?= esc((string) $attrValue) ?>"<?php endforeach; ?>>
                    <?php foreach ($field['options'] as $optionValue => $optionLabel): ?>
                        <option value="<?= esc((string) $optionValue) ?>" <?= (string) ($field['value'] ?? '') === (string) $optionValue ? 'selected' : '' ?>><?= esc((string) $optionLabel) ?></option>
                    <?php endforeach; ?>
                </select>
            <?php elseif (($field['type'] ?? '') === 'date'): ?>
                <input class="<?= $input ?>" type="date" name="<?= esc($field['name']) ?>" value="<?= esc((string) ($field['value'] ?? '')) ?>">
            <?php else: ?>
                <input class="<?= $input ?>" type="search" spellcheck="false" autocomplete="off" data-auto-search name="<?= esc($field['name']) ?>" value="<?= esc((string) ($field['value'] ?? '')) ?>" placeholder="<?= esc($field['placeholder'] ?? '') ?>">
            <?php endif; ?>
        </label>
    <?php endforeach; ?>

    <div class="flex items-end gap-2 sm:col-span-2 <?= $span ?>">
        <button type="submit" class="rounded-full bg-brand px-5 py-2 text-sm font-semibold text-white transition hover:bg-brand-dark focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/50">Apply</button>
        <?php if ($filtered): ?>
            <a class="px-2 py-2 text-sm font-semibold text-brand hover:underline" href="<?= esc($clearUrl) ?>">Clear</a>
        <?php endif; ?>
    </div>

</form>
