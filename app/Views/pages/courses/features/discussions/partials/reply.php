<?php
use CodeIgniter\I18n\Time;

$authorName = trim((string) $reply['author_name']) !== '' ? trim((string) $reply['author_name']) : 'Unknown';
$isAnswer = !empty($reply['is_answer']);
?>

<article
    class="group relative flex flex-col gap-3 rounded-2xl border bg-white px-6 py-4 <?= $isAnswer ? 'border-[#2E7D4F]/50 bg-[#E8F5EC]/40' : 'border-gray-200' ?>"
    data-reply
    data-reactions
    data-reply-id="<?= esc($reply['id']) ?>">

    <?php
    $menuItems = [];

    if (!empty($canMarkAnswer)) {
        $menuItems[] = ['attrs' => 'data-mark-answer', 'label' => $isAnswer ? 'Unmark answer' : 'Mark as answer', 'labelAttr' => 'data-mark-answer-label'];
    }

    if (!empty($reply['can_delete'])) {
        $menuItems[] = ['attrs' => 'data-delete-reply', 'label' => 'Delete', 'tone' => 'danger'];
    }
    ?>

    <?= view('pages/courses/features/discussions/partials/hover-menu', ['own' => $reply['own_reaction'], 'items' => $menuItems], ['saveData' => false]) ?>

    <div class="flex flex-wrap items-center gap-x-3 gap-y-1 text-sm">

        <span class="font-semibold text-[#1A1C1E]"><?= esc($authorName) ?></span>

        <?php if ($reply['author_role'] === 'instructor'): ?>
            <?= view('components/status-badge', ['tone' => 'info', 'label' => 'Instructor'], ['saveData' => false]) ?>
        <?php endif; ?>

        <span class="text-xs text-[#767676]"><?= esc(Time::parse($reply['created_at'])->humanize()) ?></span>

        <span class="<?= $isAnswer ? '' : 'hidden' ?>" data-answer-badge>
            <?= view('components/status-badge', ['tone' => 'success', 'icon' => 'check', 'label' => 'Answer'], ['saveData' => false]) ?>
        </span>

    </div>

    <p class="whitespace-pre-line text-sm leading-relaxed text-[#1A1C1E]"><?= esc($reply['body']) ?></p>

    <?php if ($reply['attachments'] !== []): ?>
        <?= view('pages/courses/features/discussions/partials/attachments', ['files' => $reply['attachments']], ['saveData' => false]) ?>
    <?php endif; ?>

    <?= view('pages/courses/features/discussions/partials/reactions', [
        'counts' => $reply['reaction_counts'],
        'own' => $reply['own_reaction'],
    ], ['saveData' => false]) ?>

</article>
