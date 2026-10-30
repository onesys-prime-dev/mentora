<?php
$messageHtml = render_announcement_message($announcement['message']);
$isOwn = (int) $announcement['author_id'] === $userId;

$quickReactions = [
    'like' => ['label' => 'Like', 'emoji' => '👍'],
    'heart' => ['label' => 'Love', 'emoji' => '❤️'],
    'haha' => ['label' => 'Haha', 'emoji' => '😂'],
    'wow' => ['label' => 'Wow', 'emoji' => '😮'],
];

$reactionTotal = array_sum($announcement['reaction_counts']);
?>

<div
    class="group relative scroll-mt-6 rounded-2xl border <?= $announcement['is_pinned'] ? 'border-brand/30' : 'border-gray-200' ?> bg-white shadow-sm"
    data-announcement-card
    data-announcement-id="<?= esc($announcement['id']) ?>"
    data-announcement-subject="<?= esc($announcement['subject'], 'attr') ?>"
    data-announcement-message="<?= esc($announcement['message'] ?: '{}', 'attr') ?>"
    data-announcement-attachments="<?= esc(json_encode(array_map(static fn ($f) => ['file_id' => $f['id'], 'original_filename' => $f['original_filename']], $announcement['attachments'])), 'attr') ?>">

    <div class="flex items-center gap-1.5 rounded-t-2xl bg-brand/5 px-5 py-1.5 text-xs font-bold text-brand <?= $announcement['is_pinned'] ? '' : 'hidden' ?>" data-pinned-banner>
        <svg class="h-3 w-3" xmlns="http://www.w3.org/2000/svg" fill="currentColor" viewBox="0 0 24 24"><path d="M14 2a1 1 0 011 1v6.17l3.293 3.293a1 1 0 01-.707 1.707H13v6a1 1 0 11-2 0v-6H6.414a1 1 0 01-.707-1.707L9 9.17V3a1 1 0 011-1h4z"/></svg>
        Pinned
    </div>

    <!-- hover overlay: quick reacts + more options -->
    <div class="absolute right-4 top-4 z-20 flex items-center gap-1 rounded-full border border-gray-200 bg-white px-1.5 py-1 opacity-0 shadow-md transition-opacity duration-150 group-hover:opacity-100" data-announcement-hover-menu>

        <?php foreach ($quickReactions as $key => $r): ?>
            <button
                type="button"
                data-react="<?= esc($key) ?>"
                title="<?= esc($r['label']) ?>"
                class="flex h-8 w-8 items-center justify-center rounded-full text-lg transition hover:scale-110 hover:bg-gray-100 <?= $announcement['own_reaction'] === $key ? 'bg-brand/10' : '' ?>">
                <?= $r['emoji'] ?>
            </button>
        <?php endforeach; ?>

        <div class="relative">
            <button type="button" data-emoji-panel-toggle title="More reactions" class="flex h-8 w-8 items-center justify-center rounded-full text-[#767676] transition hover:bg-gray-100" aria-label="More reactions">
                <svg class="h-4 w-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6v12m6-6H6"/></svg>
            </button>
            <div class="absolute right-0 top-9 z-30 hidden" data-emoji-panel data-action-menu-panel></div>
        </div>

        <?php if ($announcement['can_edit'] || $announcement['can_delete'] || $announcement['can_pin']): ?>

            <span class="mx-1 h-5 w-px bg-gray-200"></span>

            <div class="relative">

                <button type="button" data-more-options-toggle title="More options" class="flex h-8 w-8 items-center justify-center rounded-full text-gray-500 transition hover:bg-gray-100" aria-label="More options">
                    <svg class="h-5 w-5" xmlns="http://www.w3.org/2000/svg" fill="currentColor" viewBox="0 0 24 24"><circle cx="12" cy="5" r="1.8"/><circle cx="12" cy="12" r="1.8"/><circle cx="12" cy="19" r="1.8"/></svg>
                </button>

                <div class="absolute right-0 top-9 z-30 hidden w-40 overflow-hidden rounded-xl border border-gray-200 bg-white py-1 shadow-xl" data-more-options-panel data-action-menu-panel>

                    <?php if ($announcement['can_edit']): ?>
                        <button type="button" data-edit-announcement class="flex w-full items-center gap-2 px-4 py-2 text-left text-sm text-gray-700 hover:bg-gray-50">Edit</button>
                    <?php endif; ?>

                    <?php if ($announcement['can_pin']): ?>
                        <button type="button" data-toggle-pin class="flex w-full items-center gap-2 px-4 py-2 text-left text-sm text-gray-700 hover:bg-gray-50">
                            <span data-toggle-pin-label><?= $announcement['is_pinned'] ? 'Unpin' : 'Pin for everyone' ?></span>
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
                    <span class="text-xs text-[#767676]"><?= esc(date('M j, Y g:i A', strtotime($announcement['created_at']))) ?></span>
                </div>
                <span class="text-xs text-gray-500"><?= $announcement['author_role'] === 'instructor' ? 'Instructor' : 'Student' ?></span>
            </div>
        </div>

        <!-- normal display content; hidden in place while this card is being edited -->
        <div class="flex flex-col gap-3" data-card-display>

            <span class="text-xl font-bold text-brand"><?= esc($announcement['subject']) ?></span>

            <div class="text-[#1A1C1E] leading-relaxed [&_p]:min-h-[1em]" data-announcement-message data-collapsed="1">
                <?= $messageHtml ?>
            </div>
            <button type="button" data-see-more class="hidden self-start text-sm font-semibold text-brand hover:underline">See more</button>

            <?php if ($announcement['attachments'] !== []): ?>

                <div class="grid grid-cols-4 gap-2">
                    <?php foreach ($announcement['attachments'] as $file): ?>
                        <?php $isImage = str_starts_with((string) $file['mime_type'], 'image/'); ?>
                        <a href="<?= esc($file['url']) ?>" target="_blank" rel="noopener" class="group flex flex-col items-center justify-center gap-1 rounded-xl border border-gray-200 bg-gray-50 p-2 text-center transition hover:border-brand/40">
                            <?php if ($isImage): ?>
                                <img src="<?= esc($file['url']) ?>" class="h-14 w-full rounded-lg object-cover" alt="">
                            <?php else: ?>
                                <svg class="h-8 w-8 text-[#767676]" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M7 21h10a2 2 0 002-2V9.414a1 1 0 00-.293-.707l-5.414-5.414A1 1 0 0012.586 3H7a2 2 0 00-2 2v14a2 2 0 002 2z"/></svg>
                            <?php endif; ?>
                            <span class="w-full truncate text-[11px] text-gray-500"><?= esc($file['original_filename']) ?></span>
                        </a>
                    <?php endforeach; ?>
                </div>

            <?php endif; ?>

            <div class="flex items-center gap-1.5 text-sm text-gray-500 <?= $reactionTotal > 0 ? '' : 'hidden' ?>" data-reaction-summary>
                <?php foreach ($announcement['reaction_counts'] as $emojiKey => $count): ?>
                    <?php if ($count > 0): ?>
                        <span class="flex items-center gap-1 rounded-full bg-gray-100 px-2 py-0.5">
                            <?= $quickReactions[$emojiKey]['emoji'] ?? esc($emojiKey) ?> <?= esc($count) ?>
                        </span>
                    <?php endif; ?>
                <?php endforeach; ?>
            </div>

        </div>

        <!-- in-place editor; the "existing card converted to composer" for Edit -->
        <div class="hidden flex-col gap-3 rounded-2xl border border-gray-200" data-card-editor>

            <div class="border-b border-gray-100 px-4 py-3">
                <input
                    type="text"
                    data-card-editor-subject
                    placeholder="Add a subject"
                    autocomplete="off"
                    class="w-full text-lg font-bold text-brand placeholder:text-gray-300 placeholder:font-bold outline-none">
            </div>

            <div class="border-b border-gray-100 px-4 py-2 flex items-center gap-1">
                <button type="button" data-action="bold" title="Bold" class="rounded-lg p-1.5 text-gray-500 hover:bg-gray-100" aria-label="Bold"><svg class="h-4 w-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M6 4h8a3.5 3.5 0 010 7H6zM6 11h9a3.5 3.5 0 010 7H6z"/></svg></button>
                <button type="button" data-action="italic" title="Italic" class="rounded-lg p-1.5 text-gray-500 hover:bg-gray-100" aria-label="Italic"><svg class="h-4 w-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M10 4h8M6 20h8M13 4L9 20"/></svg></button>
                <button type="button" data-action="underline" title="Underline" class="rounded-lg p-1.5 text-gray-500 hover:bg-gray-100" aria-label="Underline"><svg class="h-4 w-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 4v7a6 6 0 0012 0V4M4 20h16"/></svg></button>
                <span class="mx-1 h-5 w-px bg-gray-200"></span>
                <button type="button" data-action="insertUnorderedList" title="Bulleted list" class="rounded-lg p-1.5 text-gray-500 hover:bg-gray-100" aria-label="Bulleted list"><svg class="h-4 w-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M8 6h13M8 12h13M8 18h13M3 6h.01M3 12h.01M3 18h.01"/></svg></button>
                <button type="button" data-action="insertOrderedList" title="Numbered list" class="rounded-lg p-1.5 text-gray-500 hover:bg-gray-100" aria-label="Numbered list"><svg class="h-4 w-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M8 6h13M8 12h13M8 18h13M4 6h1v2M4 10h2l-2 2.5h2M4.5 16h1.5a.75.75 0 010 1.5H5m1 0a.75.75 0 010 1.5H4"/></svg></button>
            </div>

            <div class="px-4 py-3" data-card-editor-mount></div>

            <div class="flex flex-wrap gap-2 px-4" data-card-editor-attachments></div>

            <div class="flex items-center justify-between px-4 py-3">

                <div class="flex items-center gap-1">

                    <button type="button" data-card-editor-emoji-toggle title="Emoji" class="rounded-lg p-2 text-gray-500 hover:bg-gray-100" aria-label="Emoji">
                        <svg class="h-5 w-5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9.172 16.172a4 4 0 015.656 0M9 10h.01M15 10h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                    </button>

                    <button type="button" data-card-editor-attach title="Attach files" class="rounded-lg p-2 text-gray-500 hover:bg-gray-100" aria-label="Attach files">
                        <svg class="h-5 w-5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1"/></svg>
                    </button>
                    <input type="file" data-card-editor-file-input class="hidden" multiple accept=".png,.jpg,.jpeg,.gif,.webp,.pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx">

                    <div class="relative">
                        <div class="absolute left-0 bottom-full z-30 mb-2 hidden" data-card-editor-emoji-panel data-action-menu-panel></div>
                    </div>

                </div>

                <div class="flex items-center gap-2">
                    <button type="button" data-card-editor-cancel class="rounded-full px-4 py-2 text-sm font-semibold text-gray-500 transition hover:bg-gray-100">Cancel</button>
                    <button type="button" data-card-editor-save class="rounded-full bg-brand px-6 py-2 text-sm font-semibold text-white transition hover:bg-brand-dark">Save</button>
                </div>

            </div>

        </div>

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
            <span class="flex-1 rounded-2xl border border-gray-200 px-4 py-2 text-sm text-[#767676] transition hover:border-brand/40">Reply in thread</span>
        </button>

        <div class="hidden items-center gap-3" data-reply-form>
            <img src="<?= base_url('assets/images/placeholder/profile.png') ?>" class="h-8 w-8 shrink-0 rounded-full" alt="">
            <div class="flex flex-1 items-center gap-2 rounded-2xl border border-gray-200 px-4 py-2 focus-within:border-brand/50">
                <textarea
                    rows="1"
                    data-reply-input
                    placeholder="Reply in thread"
                    class="block min-h-5 flex-1 resize-none p-0 text-sm leading-5 outline-none placeholder:text-[#767676]"></textarea>
                <div class="flex items-center gap-1">
                    <button type="button" data-reply-send title="Send" class="-my-1 flex h-7 w-7 items-center justify-center rounded-lg text-brand transition hover:bg-brand/10 disabled:opacity-40" disabled aria-label="Send">
                        <svg class="h-4 w-4" xmlns="http://www.w3.org/2000/svg" fill="currentColor" viewBox="0 0 24 24"><path d="M3.4 20.4l17.45-7.48a1 1 0 000-1.84L3.4 3.6a1 1 0 00-1.39 1.17L4.5 12 2 18.23a1 1 0 001.4 1.17z"/></svg>
                    </button>
                </div>
            </div>
        </div>

    </div>

</div>
