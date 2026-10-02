<div class="flex flex-col gap-3 rounded-2xl border border-gray-200 bg-white p-5" data-sidebar-pinned>

    <span class="text-xs font-bold uppercase tracking-wide text-[#767676]">Pinned</span>

    <?php if ($pinned === []): ?>

        <span class="text-xs text-[#767676]" data-sidebar-pinned-empty>No pinned announcements yet.</span>

    <?php else: ?>

        <div class="flex flex-col gap-1" data-sidebar-pinned-list>
            <?php foreach ($pinned as $item): ?>
                <button
                    type="button"
                    data-scroll-to-announcement="<?= esc($item['id']) ?>"
                    class="flex items-start gap-2 rounded-xl px-2 py-1.5 text-left transition hover:bg-[#0040A6]/5">
                    <span class="mt-0.5 shrink-0 text-sm">📌</span>
                    <span class="truncate text-sm text-[#1A1C1E]"><?= esc($item['subject']) ?></span>
                </button>
            <?php endforeach; ?>
        </div>

    <?php endif; ?>

</div>
