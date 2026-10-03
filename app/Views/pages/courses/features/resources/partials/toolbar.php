<?php $showFileControls = ($level ?? '') === 'unit'; ?>

<div class="flex flex-col gap-3 rounded-2xl border border-gray-200 bg-white p-4 lg:flex-row lg:items-center lg:justify-between" data-resources-toolbar>

    <label class="flex items-center gap-2 rounded-xl border border-gray-300 px-3 py-2 lg:max-w-sm focus-within:border-[#0040A6]/50">
        <svg class="h-4 w-4 shrink-0 text-[#767676]" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-4.35-4.35M17 11a6 6 0 11-12 0 6 6 0 0112 0z"/>
        </svg>
        <input
            type="search"
            data-resources-search
            placeholder="Search..."
            value="<?= esc($filters['search'] ?? '') ?>"
            autocomplete="off"
            class="w-full text-sm outline-none placeholder:text-[#767676]">
    </label>

    <div class="flex flex-wrap items-center gap-2">

        <select data-resources-sort class="rounded-xl border border-gray-300 px-3 py-2 text-sm text-[#1A1C1E] outline-none">
            <option value="name" <?= ($filters['sort'] ?? 'name') === 'name' ? 'selected' : '' ?>>Name (A–Z)</option>
            <option value="name_desc" <?= ($filters['sort'] ?? '') === 'name_desc' ? 'selected' : '' ?>>Name (Z–A)</option>
            <option value="newest" <?= ($filters['sort'] ?? '') === 'newest' ? 'selected' : '' ?>>Newest first</option>
            <option value="oldest" <?= ($filters['sort'] ?? '') === 'oldest' ? 'selected' : '' ?>>Oldest first</option>
        </select>

        <?php if ($showFileControls): ?>

            <select data-resources-type class="rounded-xl border border-gray-300 px-3 py-2 text-sm text-[#1A1C1E] outline-none">
                <option value="" <?= ($filters['type'] ?? '') === '' ? 'selected' : '' ?>>All types</option>
                <option value="pdf" <?= ($filters['type'] ?? '') === 'pdf' ? 'selected' : '' ?>>PDF</option>
                <option value="word" <?= ($filters['type'] ?? '') === 'word' ? 'selected' : '' ?>>Word</option>
                <option value="powerpoint" <?= ($filters['type'] ?? '') === 'powerpoint' ? 'selected' : '' ?>>PowerPoint</option>
                <option value="excel" <?= ($filters['type'] ?? '') === 'excel' ? 'selected' : '' ?>>Excel</option>
                <option value="image" <?= ($filters['type'] ?? '') === 'image' ? 'selected' : '' ?>>Images</option>
                <option value="video" <?= ($filters['type'] ?? '') === 'video' ? 'selected' : '' ?>>Videos</option>
                <option value="link" <?= ($filters['type'] ?? '') === 'link' ? 'selected' : '' ?>>Links</option>
                <option value="other" <?= ($filters['type'] ?? '') === 'other' ? 'selected' : '' ?>>Other</option>
            </select>

            <div class="flex items-center gap-1 rounded-xl border border-gray-300 p-1" data-resources-view-toggle>
                <button
                    type="button"
                    data-view="list"
                    class="rounded-lg px-3 py-1.5 text-sm font-semibold transition <?= $view === 'list' ? 'bg-[#0040A6] text-white' : 'text-gray-500 hover:bg-gray-50' ?>">
                    List
                </button>
                <button
                    type="button"
                    data-view="grid"
                    class="rounded-lg px-3 py-1.5 text-sm font-semibold transition <?= $view === 'grid' ? 'bg-[#0040A6] text-white' : 'text-gray-500 hover:bg-gray-50' ?>">
                    Grid
                </button>
            </div>

        <?php endif; ?>

    </div>

</div>
