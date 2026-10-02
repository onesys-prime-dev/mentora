<?php
$messageHtml = render_announcement_message($announcement['message']);
$isOwn = (int) $announcement['author_id'] === $userId;

$quickReactions = [
    'like' => ['label' => 'Like', 'emoji' => '👍'],
    'heart' => ['label' => 'Love', 'emoji' => '❤️'],
    'haha' => ['label' => 'Haha', 'emoji' => '😂'],
    'wow' => ['label' => 'Wow', 'emoji' => '😮'],
];
?>

<div
    class="group relative scroll-mt-6 rounded-2xl border <?= $announcement['is_pinned'] ? 'border-[#0040A6]/30' : 'border-gray-200' ?> bg-white shadow-sm"
    data-announcement-card
    data-announcement-id="<?= esc($announcement['id']) ?>"
    data-announcement-subject="<?= esc($announcement['subject'], 'attr') ?>"
    data-announcement-message="<?= esc($announcement['message'] ?: '{}', 'attr') ?>"
    data-announcement-attachments="<?= esc(json_encode(array_map(static fn ($f) => ['file_id' => $f['id'], 'original_filename' => $f['original_filename']], $announcement['attachments'])), 'attr') ?>">

    <?php if ($announcement['is_pinned']): ?>
        <div class="flex items-center gap-1.5 rounded-t-2xl bg-[#0040A6]/5 px-5 py-1.5 text-xs font-bold text-[#0040A6]">
            <svg class="h-3 w-3" xmlns="http://www.w3.org/2000/svg" fill="currentColor" viewBox="0 0 24 24"><path d="M14 2a1 1 0 011 1v6.17l3.293 3.293a1 1 0 01-.707 1.707H13v6a1 1 0 11-2 0v-6H6.414a1 1 0 01-.707-1.707L9 9.17V3a1 1 0 011-1h4z"/></svg>
            Pinned
        </div>
    <?php endif; ?>

    <!-- hover overlay: quick reacts + more options -->
    <div class="absolute right-4 top-4 z-20 flex items-center gap-1 rounded-full border border-gray-200 bg-white px-1.5 py-1 opacity-0 shadow-md transition-opacity duration-150 group-hover:opacity-100" data-announcement-hover-menu>

        <?php foreach ($quickReactions as $key => $r): ?>
            <button
                type="button"
                data-react="<?= esc($key) ?>"
                title="<?= esc($r['label']) ?>"
                class="flex h-8 w-8 items-center justify-center rounded-full text-lg transition hover:scale-110 hover:bg-gray-100 <?= $announcement['own_reaction'] === $key ? 'bg-[#0040A6]/10' : '' ?>">
                <?= $r['emoji'] ?>
            </button>
        <?php endforeach; ?>

        <div class="relative">
            <button type="button" data-emoji-panel-toggle title="More reactions" class="flex h-8 w-8 items-center justify-center rounded-full text-gray-400 transition hover:bg-gray-100">
                <svg class="h-4 w-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6v12m6-6H6"/></svg>
            </button>
            <div class="absolute right-0 top-9 z-30 hidden" data-emoji-panel data-action-menu-panel></div>
        </div>

        <?php if ($announcement['can_edit'] || $announcement['can_delete'] || $announcement['can_pin']): ?>

            <span class="mx-1 h-5 w-px bg-gray-200"></span>

            <div class="relative">

                <button type="button" data-more-options-toggle title="More options" class="flex h-8 w-8 items-center justify-center rounded-full text-gray-500 transition hover:bg-gray-100">
                    <svg class="h-5 w-5" xmlns="http://www.w3.org/2000/svg" fill="currentColor" viewBox="0 0 24 24"><circle cx="12" cy="5" r="1.8"/><circle cx="12" cy="12" r="1.8"/><circle cx="12" cy="19" r="1.8"/></svg>
                </button>

                <div class="absolute right-0 top-9 z-30 hidden w-40 overflow-hidden rounded-xl border border-gray-200 bg-white py-1 shadow-xl" data-more-options-panel data-action-menu-panel>

                    <?php if ($announcement['can_edit']): ?>
                        <button type="button" data-edit-announcement class="flex w-full items-center gap-2 px-4 py-2 text-left text-sm text-gray-700 hover:bg-gray-50">Edit</button>
                    <?php endif; ?>

                    <?php if ($announcement['can_pin']): ?>
                        <button type="button" data-toggle-pin class="flex w-full items-center gap-2 px-4 py-2 text-left text-sm text-gray-700 hover:bg-gray-50">
                            <?= $announcement['is_pinned'] ? 'Unpin' : 'Pin for everyone' ?>
                        </button>
                    <?php endif; ?>

                    <?php if ($announcement['can_delete']): ?>
                        <button type="button" data-delete-announcement class="flex w-full items-center gap-2 px-4 py-2 text-left text-sm text-red-600 hover:bg-red-50">Delete</button>
                    <?php endif; ?>

                </div>

            </div>

        <?php endif; ?>

    </div>

    <div class="px-6 py-5 flex flex-col gap-3">

        <div class="flex items-center gap-3">
            <img src="<?= base_url('assets/images/placeholder/profile.png') ?>" class="h-10 w-10 rounded-full" alt="">
            <div class="flex flex-col">
                <div class="flex items-center gap-2">
                    <span class="font-bold text-[#1A1C1E]"><?= esc(trim((string) $announcement['author_name'])) ?></span>
                    <span class="text-xs text-gray-400"><?= esc(date('M j, Y g:i A', strtotime($announcement['created_at']))) ?></span>
                </div>
                <span class="text-xs text-gray-500"><?= $announcement['author_role'] === 'instructor' ? 'Instructor' : 'Student' ?></span>
            </div>
        </div>

        <span class="text-xl font-bold text-[#0040A6]"><?= esc($announcement['subject']) ?></span>

        <div class="text-[#1F2937] leading-relaxed [&_p]:min-h-[1em]" data-announcement-message data-collapsed="1">
            <?= $messageHtml ?>
        </div>
        <button type="button" data-see-more class="hidden self-start text-sm font-semibold text-[#0040A6] hover:underline">See more</button>

        <?php if ($announcement['attachments'] !== []): ?>

            <div class="grid grid-cols-4 gap-2">
                <?php foreach ($announcement['attachments'] as $file): ?>
                    <?php $isImage = str_starts_with((string) $file['mime_type'], 'image/'); ?>
                    <a href="<?= esc($file['url']) ?>" target="_blank" rel="noopener" class="group flex flex-col items-center justify-center gap-1 rounded-xl border border-gray-200 bg-gray-50 p-2 text-center transition hover:border-[#0040A6]/40">
                        <?php if ($isImage): ?>
                            <img src="<?= esc($file['url']) ?>" class="h-14 w-full rounded-lg object-cover" alt="">
                        <?php else: ?>
                            <svg class="h-8 w-8 text-gray-400" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M7 21h10a2 2 0 002-2V9.414a1 1 0 00-.293-.707l-5.414-5.414A1 1 0 0012.586 3H7a2 2 0 00-2 2v14a2 2 0 002 2z"/></svg>
                        <?php endif; ?>
                        <span class="w-full truncate text-[11px] text-gray-500"><?= esc($file['original_filename']) ?></span>
                    </a>
                <?php endforeach; ?>
            </div>

        <?php endif; ?>

        <?php
        $reactionTotal = array_sum($announcement['reaction_counts']);
        ?>
        <?php if ($reactionTotal > 0): ?>
            <div class="flex items-center gap-1.5 text-sm text-gray-500" data-reaction-summary>
                <?php foreach ($announcement['reaction_counts'] as $emojiKey => $count): ?>
                    <?php if ($count > 0): ?>
                        <span class="flex items-center gap-1 rounded-full bg-gray-100 px-2 py-0.5">
                            <?= $quickReactions[$emojiKey]['emoji'] ?? esc($emojiKey) ?> <?= esc($count) ?>
                        </span>
                    <?php endif; ?>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

    </div>

    <?php if ($announcement['replies'] !== []): ?>

        <div class="flex flex-col gap-3 border-t border-gray-100 px-6 py-4" data-reply-thread>

            <?php foreach ($announcement['replies'] as $reply): ?>
                <?= view('pages/courses/features/announcements/partials/reply', [
                    'reply' => $reply,
                    'userId' => $userId,
                ]) ?>
            <?php endforeach; ?>

        </div>

    <?php endif; ?>

    <!-- reply in thread -->
    <div class="border-t border-gray-100 px-6 py-4" data-reply-row>

        <button type="button" data-reply-trigger class="flex w-full items-center gap-3 text-left">
            <img src="<?= base_url('assets/images/placeholder/profile.png') ?>" class="h-8 w-8 shrink-0 rounded-full" alt="">
            <span class="flex-1 rounded-2xl border border-gray-200 px-4 py-2 text-sm text-gray-400 transition hover:border-[#0040A6]/40">Reply in thread</span>
        </button>

        <div class="hidden items-center gap-3" data-reply-form>
            <img src="<?= base_url('assets/images/placeholder/profile.png') ?>" class="h-8 w-8 shrink-0 rounded-full" alt="">
            <div class="flex flex-1 items-end gap-2 rounded-2xl border border-gray-200 px-4 py-2 focus-within:border-[#0040A6]/50">
                <textarea
                    rows="1"
                    data-reply-input
                    placeholder="Reply in thread"
                    class="flex-1 resize-none text-sm outline-none placeholder:text-gray-400"></textarea>
                <div class="flex items-center gap-1 pb-0.5">
                    <button type="button" data-reply-send title="Send" class="rounded-lg p-1.5 text-[#0040A6] transition hover:bg-[#0040A6]/10 disabled:opacity-40" disabled>
                        <svg class="h-4 w-4" xmlns="http://www.w3.org/2000/svg" fill="currentColor" viewBox="0 0 24 24"><path d="M3.4 20.4l17.45-7.48a1 1 0 000-1.84L3.4 3.6a1 1 0 00-1.39 1.17L4.5 12 2 18.23a1 1 0 001.4 1.17z"/></svg>
                    </button>
                </div>
            </div>
        </div>

    </div>

</div>
