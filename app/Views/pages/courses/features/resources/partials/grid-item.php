<div
    id="resource-<?= esc($resource['resource_id']) ?>"
    class="group relative flex scroll-mt-6 flex-col gap-3 rounded-2xl border border-gray-200 p-5 <?= !empty($resource['is_locked']) ? 'bg-gray-50' : 'bg-white' ?>"
    data-resource-row
    <?= !empty($resource['is_locked']) ? 'data-resource-locked' : '' ?>
    data-resource-id="<?= esc($resource['resource_id']) ?>">

    <div class="flex h-20 items-center justify-center rounded-xl bg-brand/5">
        <?= resource_type_icon($resource['type_bucket'], 'h-9 w-9') ?>
    </div>

    <div class="flex min-w-0 flex-col gap-0.5">
        <span class="truncate text-sm font-medium text-[#1A1C1E]" title="<?= esc($resource['display_title']) ?>"><?= esc($resource['display_title']) ?></span>
        <span class="text-xs text-gray-500">
            <?= esc($resource['type_label']) ?>
            <?php if ($resource['size_label']): ?> · <?= esc($resource['size_label']) ?><?php endif; ?>
        </span>

        <?php if (!empty($resource['is_locked'])): ?>
            <span class="flex items-center gap-1 text-xs font-medium text-[#946200]" data-resource-lock-note>
                <?= icon('lock', 'h-3.5 w-3.5') ?>
                <?= esc($resource['lock_message'] ?? 'Locked until you finish the earlier items.') ?>
            </span>
        <?php endif; ?>
    </div>

    <div class="absolute right-3 top-3">

        <?php if (!empty($resource['is_locked'])): ?>
            <span class="flex p-1.5 text-[#946200]" title="<?= esc($resource['lock_message'] ?? 'Locked') ?>" data-resource-lock-icon>
                <?= icon('lock', 'h-5 w-5') ?>
            </span>
        <?php else: ?>
        <button type="button" data-resource-menu-toggle title="More options" class="rounded-lg bg-white/80 p-1.5 text-[#767676] opacity-0 transition hover:bg-gray-100 group-hover:opacity-100" aria-label="More options">
            <svg class="h-5 w-5" xmlns="http://www.w3.org/2000/svg" fill="currentColor" viewBox="0 0 24 24"><circle cx="12" cy="5" r="1.8"/><circle cx="12" cy="12" r="1.8"/><circle cx="12" cy="19" r="1.8"/></svg>
        </button>

        <div class="absolute right-0 top-9 z-20 hidden w-44 overflow-hidden rounded-xl border border-gray-200 bg-white py-1 shadow-xl" data-resource-menu-panel data-action-menu-panel>

            <a href="<?= esc($resource['view_url']) ?>" target="_blank" rel="noopener" class="flex w-full items-center px-4 py-2 text-left text-sm text-gray-700 hover:bg-gray-50">View</a>

            <?php if ($resource['download_url']): ?>
                <a href="<?= esc($resource['download_url']) ?>" class="flex w-full items-center px-4 py-2 text-left text-sm text-gray-700 hover:bg-gray-50">Download</a>
            <?php endif; ?>

            <button type="button" data-resource-share data-resource-id="<?= esc($resource['resource_id']) ?>" class="flex w-full items-center px-4 py-2 text-left text-sm text-gray-700 hover:bg-gray-50">Share link</button>

        </div>
        <?php endif; ?>

    </div>

</div>
