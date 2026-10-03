<div class="flex flex-col divide-y divide-gray-100 rounded-2xl border border-gray-200 bg-white" data-resources-folder-list>

    <?php foreach ($items as $item): ?>

        <button
            type="button"
            data-resources-open-<?= esc($kind) ?>="<?= esc($item['id']) ?>"
            class="flex items-center gap-4 px-5 py-4 text-left transition hover:bg-gray-50">

            <?= icon('folder', 'h-6 w-6') ?>

            <span class="min-w-0 flex-1 truncate font-semibold text-[#1A1C1E]"><?= esc($item['title']) ?></span>

            <svg class="h-5 w-5 shrink-0 text-[#767676]" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M9 18l6-6-6-6"/>
            </svg>

        </button>

    <?php endforeach; ?>

    <?php if ($items === []): ?>
        <div class="flex flex-col items-center justify-center gap-2 px-8 py-14 text-center">
            <?= icon('folder', 'h-10 w-10 text-gray-300') ?>
            <span class="text-sm font-semibold text-[#646464]"><?= esc($emptyLabel) ?></span>
        </div>
    <?php endif; ?>

</div>
