<?php
/**
 * Discussion item editor: the same composer as the Discussions tab (title,
 * rich-text prompt, attachments), always open and autosaved with the item.
 * Uses: currentItem and discussion (thread + replies, or null).
 */
use App\Services\Courses\ModuleService;

$thread = $discussion['thread'] ?? null;
$replyCount = is_array($discussion['replies'] ?? null)
    ? count($discussion['replies'])
    : (int) ($thread['reply_count'] ?? 0);
$threadOfferingId = (int) ($thread['offering_id'] ?? 0);

// A body saved as plain text (older items) is shown as paragraphs.
$message = ModuleService::discussionMessage($thread['body'] ?? '') ?: '{}';

$attachments = array_map(
    static fn ($f) => ['file_id' => $f['id'], 'original_filename' => $f['original_filename']],
    $thread['attachments'] ?? []
);
?>
<section
    class="flex flex-col gap-4"
    data-discussion-editor
    data-learning-item-id="<?= (int) $currentItem['id'] ?>"
    data-discussion-title="<?= esc($currentItem['title'], 'attr') ?>"
    data-discussion-message="<?= esc($message, 'attr') ?>"
    data-discussion-attachments="<?= esc(json_encode($attachments), 'attr') ?>">

    <div class="flex flex-wrap items-center justify-between gap-3">
        <div class="flex items-center gap-3">
            <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-brand/10">
                <?= icon('discussion', 'h-5 w-5 text-brand') ?>
            </div>
            <div class="flex flex-col">
                <span class="text-sm font-semibold text-[#1A1C1E]">Class discussion</span>
                <span class="text-xs text-[#767676]">
                    Students complete this item by posting a reply.
                    <?= $replyCount ?> <?= $replyCount === 1 ? 'reply' : 'replies' ?> so far.
                </span>
            </div>
        </div>

        <?php if ($thread): ?>
            <a
                href="<?= base_url('courses/' . $threadOfferingId . '/discussions?thread=' . (int) $thread['id']) ?>"
                class="text-sm font-semibold text-brand hover:underline">
                Open in Discussions tab
            </a>
        <?php endif; ?>
    </div>

    <?= view('pages/courses/features/discussions/partials/composer', ['embedded' => true], ['saveData' => false]) ?>

    <p class="hidden text-xs text-[#C33939]" data-discussion-error></p>

    <p class="text-xs text-[#767676]">Changes are saved automatically and shown in the course's Discussions tab.</p>

</section>
