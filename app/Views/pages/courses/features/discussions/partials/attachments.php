<div class="grid grid-cols-2 gap-2 sm:grid-cols-4">

    <?php foreach ($files as $file): ?>

        <?php $isImage = str_starts_with((string) $file['mime_type'], 'image/'); ?>

        <a href="<?= esc($file['url']) ?>" target="_blank" rel="noopener" class="flex flex-col items-center justify-center gap-1 rounded-xl border border-gray-200 bg-gray-50 p-2 text-center transition hover:border-brand/40">

            <?php if ($isImage): ?>
                <img src="<?= esc($file['url']) ?>" class="h-14 w-full rounded-lg object-cover" alt="">
            <?php else: ?>
                <?= icon('file', 'h-8 w-8') ?>
            <?php endif; ?>

            <span class="w-full truncate text-[11px] text-[#646464]"><?= esc($file['original_filename']) ?></span>

        </a>

    <?php endforeach; ?>

</div>
