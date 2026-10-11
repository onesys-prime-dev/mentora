<?php

/*
|--------------------------------------------------------------------------
| Toolbar — the filter bar above a list
|--------------------------------------------------------------------------
| Params: action (required), fields (required), filtered (bool, shows Clear),
| clearUrl.
|
| fields: list of
|   ['type' => 'search', 'name' => 'q', 'label' => 'Search', 'placeholder' => '...', 'value' => '', 'span' => 'lg:col-span-2']
|   ['type' => 'select', 'name' => 'role', 'label' => 'Role', 'options' => ['' => 'Any role', 'student' => 'Student'], 'value' => '']
|
| Filters travel in the address (GET), so a filtered view can be bookmarked or
| shared, and every sort and paging link keeps them.
|--------------------------------------------------------------------------
*/

$filtered = $filtered ?? false;
$clearUrl = $clearUrl ?? $action;

$input = 'w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm focus:border-[#0040A6]/60 focus:outline-none focus:ring-2 focus:ring-[#0040A6]/40';
?>

<form method="get" action="<?= esc($action) ?>" role="search" class="grid gap-3 rounded-2xl bg-white p-4 shadow-sm sm:grid-cols-2 lg:grid-cols-4" data-toolbar>

    <?php foreach ($fields as $field): ?>
        <label class="flex flex-col gap-1 text-xs font-medium text-gray-600 <?= esc($field['span'] ?? '') ?>">
            <?= esc($field['label']) ?>
            <?php if (($field['type'] ?? 'search') === 'select'): ?>
                <select class="<?= $input ?>" name="<?= esc($field['name']) ?>">
                    <?php foreach ($field['options'] as $optionValue => $optionLabel): ?>
                        <option value="<?= esc((string) $optionValue) ?>" <?= (string) ($field['value'] ?? '') === (string) $optionValue ? 'selected' : '' ?>><?= esc((string) $optionLabel) ?></option>
                    <?php endforeach; ?>
                </select>
            <?php else: ?>
                <input class="<?= $input ?>" type="search" name="<?= esc($field['name']) ?>" value="<?= esc((string) ($field['value'] ?? '')) ?>" placeholder="<?= esc($field['placeholder'] ?? '') ?>">
            <?php endif; ?>
        </label>
    <?php endforeach; ?>

    <div class="flex items-end gap-2 sm:col-span-2 lg:col-span-4">
        <button type="submit" class="rounded-full bg-[#0040A6] px-5 py-2 text-sm font-semibold text-white transition hover:bg-[#002068] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#0040A6]/50">Apply</button>
        <?php if ($filtered): ?>
            <a class="px-2 py-2 text-sm font-semibold text-[#0040A6] hover:underline" href="<?= esc($clearUrl) ?>">Clear</a>
        <?php endif; ?>
    </div>

</form>
