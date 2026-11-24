<?php
/**
 * "Finish your profile": what is still empty, each item opening its edit dialog. No percentage (D27).
 *
 * @var array<string, array{label: string, dialog: string}> $items non-empty
 */
?>
<section class="flex flex-col gap-3 rounded-2xl bg-brand-tint p-4 sm:p-6" aria-labelledby="profile-checklist" data-profile-checklist>
    <div class="flex flex-wrap items-center justify-between gap-x-3 gap-y-1">
        <h2 id="profile-checklist" class="text-sm font-bold text-brand">Finish your profile</h2>
        <form method="post" action="<?= esc(site_url('profile/checklist/dismiss'), 'attr') ?>">
            <?= csrf_field() ?>
            <button type="submit" class="rounded-full px-3 py-1 text-xs font-semibold text-brand transition hover:bg-white focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/50 pointer-coarse:min-h-11">Dismiss</button>
        </form>
    </div>
    <p class="text-sm text-[#3D3D3D]">A few things people in your school may look for.</p>
    <ul class="flex flex-wrap gap-2">
        <?php foreach ($items as $key => $item): ?>
            <li>
                <button type="button" data-checklist-item="<?= esc($key, 'attr') ?>" data-dialog-open="<?= esc($item['dialog'], 'attr') ?>" class="inline-flex items-center gap-2 rounded-full border border-brand/30 bg-white px-4 py-2 text-sm font-semibold text-brand transition hover:bg-brand-tint focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/50 pointer-coarse:min-h-11"><?= icon('pencil', 'h-4 w-4') ?><?= esc($item['label']) ?></button>
            </li>
        <?php endforeach; ?>
    </ul>
</section>
