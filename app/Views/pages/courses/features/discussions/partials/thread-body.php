<?php
/*
|--------------------------------------------------------------------------
| The conversation itself — opening post, replies, reply box. Shared by the
| Discussions tab and (later) a discussion learning item, so both show the
| same thread the same way. Params: thread (with permission flags), replies.
|--------------------------------------------------------------------------
*/

use CodeIgniter\I18n\Time;

$threadId = (int) $thread['id'];
$canMarkAnswer = !empty($thread['can_mark_answer']);
$authorName = trim((string) $thread['author_name']) !== '' ? trim((string) $thread['author_name']) : 'Unknown';
$replyCount = count($replies);
?>

<div
    class="flex flex-col gap-6"
    data-thread-body
    data-thread-id="<?= esc($threadId) ?>"
    data-thread-title="<?= esc($thread['title'], 'attr') ?>"
    data-thread-message="<?= esc($thread['body'] ?: '{}', 'attr') ?>"
    data-thread-attachments="<?= esc(json_encode(array_map(static fn ($f) => ['file_id' => $f['id'], 'original_filename' => $f['original_filename']], $thread['attachments'])), 'attr') ?>">

    <!-- opening post -->
    <article class="group relative flex flex-col gap-4 rounded-2xl border bg-white px-6 py-6 <?= $thread['is_pinned'] ? 'border-[#0040A6]/30' : 'border-gray-200' ?>" data-reactions>

        <?php
        $menuItems = [];

        if ($thread['can_edit']) {
            $menuItems[] = ['attrs' => 'data-thread-edit', 'label' => 'Edit'];
        }

        if ($thread['can_pin']) {
            $menuItems[] = ['attrs' => 'data-thread-pin data-value="' . ($thread['is_pinned'] ? '1' : '0') . '"', 'label' => $thread['is_pinned'] ? 'Unpin' : 'Pin', 'labelAttr' => 'data-pin-label'];
        }

        if ($thread['can_lock']) {
            $menuItems[] = ['attrs' => 'data-thread-lock data-value="' . ($thread['is_locked'] ? '1' : '0') . '"', 'label' => $thread['is_locked'] ? 'Unlock' : 'Lock', 'labelAttr' => 'data-lock-label'];
        }

        if ($thread['can_delete']) {
            $menuItems[] = ['attrs' => 'data-thread-delete', 'label' => 'Delete', 'tone' => 'danger'];
        }
        ?>

        <?= view('pages/courses/features/discussions/partials/hover-menu', ['own' => $thread['own_reaction'], 'items' => $menuItems], ['saveData' => false]) ?>

        <div class="flex flex-wrap items-center gap-2">

            <h2 class="min-w-0 text-2xl font-bold text-[#1A1C1E]"><?= esc($thread['title']) ?></h2>

            <span class="<?= $thread['is_pinned'] ? '' : 'hidden' ?>" data-badge-pinned>
                <?= view('components/status-badge', ['tone' => 'info', 'icon' => 'pin', 'label' => 'Pinned'], ['saveData' => false]) ?>
            </span>

            <span class="<?= $thread['is_locked'] ? '' : 'hidden' ?>" data-badge-locked>
                <?= view('components/status-badge', ['tone' => 'neutral', 'icon' => 'lock', 'label' => 'Locked'], ['saveData' => false]) ?>
            </span>

            <?php if ($thread['module_title'] !== null): ?>
                <?= view('components/status-badge', [
                    'tone' => 'neutral',
                    'icon' => 'modules',
                    'label' => $thread['module_title'] . ' › ' . $thread['unit_title'],
                ], ['saveData' => false]) ?>
            <?php endif; ?>

        </div>

        <div class="flex flex-wrap items-center gap-x-3 gap-y-1 text-sm text-[#767676]">
            <span class="font-semibold text-[#646464]"><?= esc($authorName) ?></span>
            <?php if ($thread['author_role'] === 'instructor'): ?>
                <?= view('components/status-badge', ['tone' => 'info', 'label' => 'Instructor'], ['saveData' => false]) ?>
            <?php endif; ?>
            <span><?= esc(Time::parse($thread['created_at'])->humanize()) ?></span>
        </div>

        <div class="leading-relaxed text-[#1A1C1E] [&_p]:min-h-[1em]" data-thread-message-display>
            <?= render_announcement_message($thread['body']) ?>
        </div>

        <?php if ($thread['attachments'] !== []): ?>
            <?= view('pages/courses/features/discussions/partials/attachments', ['files' => $thread['attachments']], ['saveData' => false]) ?>
        <?php endif; ?>

        <?= view('pages/courses/features/discussions/partials/reactions', [
            'counts' => $thread['reaction_counts'],
            'own' => $thread['own_reaction'],
        ], ['saveData' => false]) ?>

    </article>

    <!-- replies -->
    <section class="flex flex-col gap-3" aria-label="Replies">

        <h3 class="text-lg font-bold text-[#0040A6]">
            <span data-reply-count><?= $replyCount ?></span> <span data-reply-count-label><?= $replyCount === 1 ? 'Reply' : 'Replies' ?></span>
        </h3>

        <div class="flex flex-col gap-3" data-reply-list>

            <?php foreach ($replies as $reply): ?>
                <?= view('pages/courses/features/discussions/partials/reply', [
                    'reply' => $reply,
                    'canMarkAnswer' => $canMarkAnswer,
                ], ['saveData' => false]) ?>
            <?php endforeach; ?>

        </div>

        <div class="<?= $replyCount === 0 ? '' : 'hidden' ?>" data-no-replies>
            <?= view('components/empty-state', [
                'icon' => 'discussion',
                'title' => 'No replies yet',
                'text' => 'Be the first to respond.',
            ], ['saveData' => false]) ?>
        </div>

    </section>

    <!-- reply box / locked notice: both always rendered so lock toggles in place -->
    <div class="flex items-center gap-3 rounded-2xl border border-gray-200 bg-gray-50 px-6 py-4 text-sm text-[#646464] <?= $thread['is_locked'] ? '' : 'hidden' ?>" data-locked-notice>
        <?= icon('lock', 'h-5 w-5') ?>
        This discussion is locked, so new replies are turned off.
    </div>

    <div class="<?= $thread['is_locked'] ? 'hidden' : '' ?>" data-reply-box-wrapper>
        <div class="flex flex-col gap-3 rounded-2xl border border-gray-200 bg-white p-4" data-reply-box>
    
                <textarea
                    rows="3"
                    data-reply-input
                    aria-label="Write a reply"
                    placeholder="Write a reply"
                    class="w-full resize-y rounded-xl border border-gray-300 px-4 py-3 text-sm outline-none transition placeholder:text-gray-500 focus:border-[#0040A6]/60"></textarea>
    
                <div class="flex flex-wrap gap-2" data-reply-attachments></div>
    
                <div class="flex items-center justify-between">
    
                    <div class="flex items-center">
                        <button type="button" data-reply-attach title="Attach files" aria-label="Attach files" class="rounded-lg p-2 text-[#646464] transition hover:bg-gray-100">
                            <?= icon('link', 'h-5 w-5 text-current') ?>
                        </button>
                        <input type="file" data-reply-file-input class="hidden" multiple accept=".png,.jpg,.jpeg,.gif,.webp,.pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx">
                    </div>
    
                    <button type="button" data-reply-send disabled class="rounded-full bg-[#0040A6] px-6 py-2 text-sm font-semibold text-white transition hover:bg-[#002068] disabled:opacity-50">
                        Reply
                    </button>
    
                </div>
    
            </div>
    </div>

</div>
