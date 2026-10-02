<div
    id="resource-<?= esc($resource['resource_id']) ?>"
    class="group flex scroll-mt-6 items-center gap-4 px-5 py-4"
    data-resource-row
    data-resource-id="<?= esc($resource['resource_id']) ?>">

    <?= resource_type_icon($resource['type_bucket'], 'h-6 w-6') ?>

    <div class="flex min-w-0 flex-1 flex-col">
        <span class="truncate font-medium text-[#1A1C1E]"><?= esc($resource['display_title']) ?></span>
        <span class="text-xs text-gray-500">
            <?= esc($resource['type_label']) ?>
            <?php if ($resource['size_label']): ?> · <?= esc($resource['size_label']) ?><?php endif; ?>
            <?php if ($resource['added_label'] !== ''): ?> · Added <?= esc($resource['added_label']) ?><?php endif; ?>
        </span>
    </div>

    <div class="relative shrink-0">

        <button type="button" data-resource-menu-toggle title="More options" class="rounded-lg p-2 text-gray-400 transition hover:bg-gray-100" aria-label="More options">
            <svg class="h-5 w-5" xmlns="http://www.w3.org/2000/svg" fill="currentColor" viewBox="0 0 24 24"><circle cx="12" cy="5" r="1.8"/><circle cx="12" cy="12" r="1.8"/><circle cx="12" cy="19" r="1.8"/></svg>
        </button>

        <div class="absolute right-0 top-9 z-20 hidden w-44 overflow-hidden rounded-xl border border-gray-200 bg-white py-1 shadow-xl" data-resource-menu-panel data-action-menu-panel>

            <a href="<?= esc($resource['view_url']) ?>" target="_blank" rel="noopener" class="flex w-full items-center px-4 py-2 text-left text-sm text-gray-700 hover:bg-gray-50">View</a>

            <?php if ($resource['download_url']): ?>
                <a href="<?= esc($resource['download_url']) ?>" class="flex w-full items-center px-4 py-2 text-left text-sm text-gray-700 hover:bg-gray-50">Download</a>
            <?php endif; ?>

            <button type="button" data-resource-share data-resource-id="<?= esc($resource['resource_id']) ?>" class="flex w-full items-center px-4 py-2 text-left text-sm text-gray-700 hover:bg-gray-50">Share link</button>

        </div>

    </div>

</div>
