<?php $isOwn = (int) $reply['author_id'] === $userId; ?>

<div class="group flex items-start gap-3" data-reply-row data-reply-id="<?= esc($reply['id']) ?>">

    <img src="<?= base_url('assets/images/placeholder/profile.png') ?>" class="h-8 w-8 shrink-0 rounded-full" alt="">

    <div class="flex flex-1 flex-col gap-0.5">

        <div class="flex items-center gap-2">
            <span class="text-sm font-bold text-[#1A1C1E]"><?= esc(trim((string) $reply['author_name'])) ?></span>
            <span class="text-xs text-[#767676]"><?= esc(date('M j, Y g:i A', strtotime($reply['created_at']))) ?></span>

            <?php if ($isOwn): ?>
                <button type="button" data-delete-reply class="ml-auto opacity-0 transition group-hover:opacity-100 text-xs text-red-500 hover:underline">Delete</button>
            <?php endif; ?>
        </div>

        <p class="text-sm text-[#374151] whitespace-pre-line" data-reply-message data-collapsed="1"><?= esc($reply['message']) ?></p>
        <button type="button" data-see-more class="hidden self-start text-xs font-semibold text-[#0040A6] hover:underline">See more</button>

    </div>

</div>
