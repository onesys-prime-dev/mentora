<?php
/**
 * Discussion item editor. Uses: currentItem, discussion (thread + replies, or
 * null) and offering_id for the link to the Discussions tab.
 */
$thread = $discussion['thread'] ?? null;
$threadBody = (string) ($thread['body'] ?? '');
$replyCount = is_array($discussion['replies'] ?? null)
    ? count($discussion['replies'])
    : (int) ($thread['reply_count'] ?? 0);
$threadOfferingId = (int) ($thread['offering_id'] ?? ($offeringId ?? 0));
?>
<section
    class="flex flex-col gap-6 rounded-2xl border border-gray-200 bg-white p-8 shadow-sm"
    data-discussion-editor
    data-learning-item-id="<?= (int) $currentItem['id'] ?>">

    <div class="flex items-center gap-4">
        <div class="flex h-12 w-12 shrink-0 items-center justify-center rounded-xl bg-brand/10">
            <?= icon('discussion', 'h-6 w-6 text-brand') ?>
        </div>
        <div class="flex flex-col">
            <span class="text-sm font-semibold text-[#1A1C1E]">Class discussion</span>
            <span class="text-xs text-[#767676]">Students complete this item by posting a reply.</span>
        </div>
    </div>

    <div class="flex flex-col gap-2">
        <label for="discussion-title" class="text-sm font-medium text-[#1A1C1E]">Title</label>
        <input
            id="discussion-title"
            type="text"
            maxlength="255"
            value="<?= esc($currentItem['title']) ?>"
            data-discussion-title
            class="w-full rounded-xl border border-gray-300 px-4 py-3 text-sm outline-none focus:border-brand">
        <p class="hidden text-xs text-[#C33939]" data-discussion-error></p>
    </div>

    <div class="flex flex-col gap-2">
        <label for="discussion-body" class="text-sm font-medium text-[#1A1C1E]">Prompt</label>
        <textarea
            id="discussion-body"
            rows="6"
            maxlength="10000"
            placeholder="What should students discuss?"
            data-discussion-body
            class="w-full rounded-xl border border-gray-300 px-4 py-3 text-sm leading-relaxed outline-none focus:border-brand"><?= esc($threadBody) ?></textarea>
        <p class="text-xs text-[#767676]">
            Students see this as the opening post. Changes are saved automatically and shown in the course's Discussions tab.
        </p>
    </div>

    <?php if ($thread): ?>
        <div class="flex flex-col gap-3 rounded-xl bg-[#F2F7FF] p-5">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <span class="text-xs font-semibold uppercase tracking-wide text-[#646464]">What students see</span>
                <a
                    href="<?= base_url('courses/' . $threadOfferingId . '/discussions?thread=' . (int) $thread['id']) ?>"
                    class="text-sm font-semibold text-brand hover:underline">
                    Open in Discussions tab
                </a>
            </div>
            <h3 class="text-lg font-bold text-brand" data-discussion-preview-title><?= esc($currentItem['title']) ?></h3>
            <p class="whitespace-pre-line text-sm leading-relaxed text-[#3D3D3D]" data-discussion-preview-body><?= $threadBody !== '' ? esc($threadBody) : 'No prompt yet.' ?></p>
            <span class="text-xs text-[#767676]"><?= $replyCount ?> <?= $replyCount === 1 ? 'reply' : 'replies' ?> so far</span>
        </div>
    <?php endif; ?>

</section>
