<?php
$sorts = ['activity' => 'Latest activity', 'newest' => 'Newest', 'replies' => 'Most replies'];

$filterOptions = [
    'all' => 'All discussions',
    'unread' => 'Unread',
    'unanswered' => 'Unanswered',
    'pinned' => 'Pinned',
    'mine' => 'Started by me',
    'modules' => 'From modules',
];
?>

<div class="flex flex-col gap-3 rounded-2xl border border-gray-200 bg-white p-4 lg:flex-row lg:items-center lg:justify-between" data-discussions-toolbar>

    <label class="flex items-center gap-2 rounded-full border border-gray-300 px-4 py-2 transition focus-within:border-brand/60 lg:w-80">
        <?= icon('search', 'h-4 w-4 shrink-0 opacity-60') ?>
        <input
            type="search"
            data-discussions-search
            role="searchbox"
            aria-label="Search discussions"
            placeholder="Search discussions"
            value="<?= esc($filters['search']) ?>"
            autocomplete="off"
            class="w-full bg-transparent text-sm outline-none placeholder:text-gray-500">
    </label>

    <div class="flex flex-wrap items-center gap-2">

        <select data-discussions-filter aria-label="Filter discussions" class="rounded-full border border-gray-300 bg-white px-4 py-2 text-sm text-[#1A1C1E] outline-none">
            <?php foreach ($filterOptions as $value => $label): ?>
                <option value="<?= esc($value) ?>" <?= $filters['filter'] === $value ? 'selected' : '' ?>><?= esc($label) ?></option>
            <?php endforeach; ?>
        </select>

        <select data-discussions-sort aria-label="Sort discussions" class="rounded-full border border-gray-300 bg-white px-4 py-2 text-sm text-[#1A1C1E] outline-none">
            <?php foreach ($sorts as $value => $label): ?>
                <option value="<?= esc($value) ?>" <?= $filters['sort'] === $value ? 'selected' : '' ?>><?= esc($label) ?></option>
            <?php endforeach; ?>
        </select>

    </div>

</div>
