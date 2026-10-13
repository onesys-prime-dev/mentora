<?php
use CodeIgniter\I18n\Time;

$excerpt = announcement_plain_text($thread['body']);
$excerpt = mb_strlen($excerpt) > 140 ? mb_substr($excerpt, 0, 139) . '…' : $excerpt;

$unread = !empty($thread['is_unread']);
$answered = $thread['answer_reply_id'] !== null;
$replies = (int) $thread['reply_count'];
$authorName = trim((string) $thread['author_name']) !== '' ? trim((string) $thread['author_name']) : 'Unknown';
?>

<div
    class="group flex cursor-pointer flex-col gap-2 rounded-2xl border bg-white px-6 py-4 transition hover:border-brand/50 hover:shadow-sm <?= $thread['is_pinned'] ? 'border-brand/30' : 'border-gray-200' ?>"
    role="link"
    tabindex="0"
    data-thread-open="<?= esc($thread['id']) ?>"
    data-thread-id="<?= esc($thread['id']) ?>">

    <div class="flex flex-wrap items-center gap-2">

        <?php if ($unread): ?>
            <span class="h-2.5 w-2.5 shrink-0 rounded-full bg-brand" aria-hidden="true"></span>
        <?php endif; ?>

        <span class="min-w-0 text-lg <?= $unread ? 'font-black' : 'font-bold' ?> text-[#1A1C1E] group-hover:text-brand"><?= esc($thread['title']) ?></span>

        <?php if ($unread): ?>
            <?= view('components/status-badge', ['tone' => 'info', 'label' => 'New activity'], ['saveData' => false]) ?>
        <?php endif; ?>

        <?php if ($thread['is_pinned']): ?>
            <?= view('components/status-badge', ['tone' => 'info', 'icon' => 'pin', 'label' => 'Pinned'], ['saveData' => false]) ?>
        <?php endif; ?>

        <?php if ($thread['is_locked']): ?>
            <?= view('components/status-badge', ['tone' => 'neutral', 'icon' => 'lock', 'label' => 'Locked'], ['saveData' => false]) ?>
        <?php endif; ?>

        <?php if ($answered): ?>
            <?= view('components/status-badge', ['tone' => 'success', 'icon' => 'check', 'label' => 'Answered'], ['saveData' => false]) ?>
        <?php endif; ?>

        <?php if ($thread['module_title'] !== null): ?>
            <?= view('components/status-badge', [
                'tone' => 'neutral',
                'icon' => 'modules',
                'label' => $thread['module_title'] . ' › ' . $thread['unit_title'],
            ], ['saveData' => false]) ?>
        <?php endif; ?>

    </div>

    <?php if ($excerpt !== ''): ?>
        <p class="line-clamp-2 text-sm text-[#646464]"><?= esc($excerpt) ?></p>
    <?php endif; ?>

    <div class="flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-[#767676]">

        <span class="font-semibold text-[#646464]"><?= esc($authorName) ?></span>

        <?php if ($thread['author_role'] === 'instructor'): ?>
            <?= view('components/status-badge', ['tone' => 'info', 'label' => 'Instructor'], ['saveData' => false]) ?>
        <?php endif; ?>

        <span><?= $replies ?> <?= $replies === 1 ? 'reply' : 'replies' ?></span>

        <span>Last activity <?= esc(Time::parse($thread['last_activity_at'] ?? $thread['created_at'])->humanize()) ?></span>

    </div>

</div>
