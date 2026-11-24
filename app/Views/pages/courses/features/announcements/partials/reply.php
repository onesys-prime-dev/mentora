<?php $isOwn = (int) $reply['author_id'] === $userId; ?>

<div class="group flex items-start gap-3" data-reply-row data-reply-id="<?= esc($reply['id']) ?>">

    <?= view('components/avatar', ($reply['author_avatar'] ?? ['person' => ['id' => (int) ($reply['author_id'] ?? 0), 'name' => trim((string) $reply['author_name'])], 'interactive' => false]) + ['size' => 'sm'], ['saveData' => false]) ?>

    <div class="flex flex-1 flex-col gap-0.5">

        <div class="flex items-center gap-2">
            <span class="text-sm font-bold text-[#1A1C1E]"><?= esc(trim((string) $reply['author_name'])) ?></span>
            <span class="text-xs text-[#767676]"><?= esc(date('M j, Y g:i A', strtotime($reply['created_at']))) ?></span>

            <?php if ($isOwn): ?>
                <button type="button" data-delete-reply class="ml-auto opacity-0 transition group-hover:opacity-100 focus-visible:opacity-100 pointer-coarse:opacity-100 pointer-coarse:min-h-11 pointer-coarse:px-2 text-xs text-red-700 hover:underline">Delete</button>
            <?php endif; ?>
        </div>

        <p class="text-sm text-[#374151] whitespace-pre-line" data-reply-message data-collapsed="1"><?= esc($reply['message']) ?></p>
        <button type="button" data-see-more class="hidden self-start pointer-coarse:min-h-11 text-xs font-semibold text-brand hover:underline">See more</button>

    </div>

</div>
