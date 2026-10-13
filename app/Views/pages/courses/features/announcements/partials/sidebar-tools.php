<?php
$search = $filters['search'] ?? '';
$sort = $filters['sort'] ?? 'newest';
?>

<div class="flex flex-col gap-4 rounded-2xl border border-gray-200 bg-white p-5" data-sidebar-tools>

    <span class="text-xs font-bold uppercase tracking-wide text-[#767676]">Announcement Tools</span>

    <label class="flex items-center gap-2 rounded-xl border border-gray-300 px-3 py-2 transition focus-within:border-brand/50">
        <svg class="h-4 w-4 shrink-0 text-[#767676]" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-4.35-4.35M17 11a6 6 0 11-12 0 6 6 0 0112 0z"/>
        </svg>
        <input
            type="search"
            data-announcements-search
            placeholder="Search announcements..."
            value="<?= esc($search) ?>"
            autocomplete="off"
            class="w-full text-sm outline-none placeholder:text-[#767676]">
    </label>

    <div class="flex flex-col gap-2">
        <span class="text-xs font-semibold text-[#646464]">Filter</span>
        <div class="flex items-center gap-2" data-announcements-filter>
            <button
                type="button"
                data-filter="all"
                class="rounded-full px-3 py-1.5 text-xs font-semibold transition <?= $activeFilter === 'all' ? 'bg-brand text-white' : 'border border-gray-300 text-[#646464] hover:bg-gray-50' ?>">
                All
            </button>
            <button
                type="button"
                data-filter="pinned"
                class="rounded-full px-3 py-1.5 text-xs font-semibold transition <?= $activeFilter === 'pinned' ? 'bg-brand text-white' : 'border border-gray-300 text-[#646464] hover:bg-gray-50' ?>">
                Pinned
            </button>
        </div>
    </div>

    <div class="flex flex-col gap-2">
        <span class="text-xs font-semibold text-[#646464]">Sort</span>
        <select data-announcements-sort class="rounded-xl border border-gray-300 px-3 py-2 text-sm text-[#1A1C1E] outline-none">
            <option value="newest" <?= $sort === 'newest' ? 'selected' : '' ?>>Newest first</option>
            <option value="oldest" <?= $sort === 'oldest' ? 'selected' : '' ?>>Oldest first</option>
        </select>
    </div>

</div>
