<?php
/**
 * The "From the instructor" tab for a parent: what the instructors posted in the child's course chat, as a
 * plain read-only list, and a button to message each instructor. No student's words, replies, composer or menus.
 * Post text is escaped here and never treated as HTML.
 *
 * @var array{instructors: list<array{id: int, name: string}>, posts: list<array<string, mixed>>} $feed
 * @var int                                                                                         $studentId the child
 */
?>

<div class="p-4 sm:p-10 flex flex-col gap-8" data-parent-instructor-feed>

    <?= view('components/page-header', ['icon' => 'message', 'title' => 'From the instructor'], ['saveData' => false]) ?>

    <?php if ($feed['instructors'] !== []): ?>
        <div class="flex flex-col items-stretch gap-3 sm:flex-row sm:flex-wrap sm:items-center">
            <?php foreach ($feed['instructors'] as $instructor): ?>
                <button
                    type="button"
                    class="rounded-full bg-brand px-4 py-2 pointer-coarse:min-h-11 text-sm break-words font-semibold text-white hover:bg-brand-dark focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/50 disabled:cursor-not-allowed disabled:opacity-50"
                    data-parent-message
                    data-instructor-id="<?= (int) $instructor['id'] ?>"
                    data-child-id="<?= (int) $studentId ?>">
                    Message <?= esc($instructor['name']) ?>
                </button>
            <?php endforeach; ?>
            <p class="text-sm text-[#646464]" role="status" data-parent-message-note hidden></p>
        </div>
    <?php endif; ?>

    <?php if ($feed['posts'] === []): ?>

        <?= view('components/empty-state', [
            'icon'  => 'message',
            'title' => 'Nothing from the instructor yet',
            'text'  => 'What the instructor writes in the course chat appears here. You cannot see the students\' messages.',
        ], ['saveData' => false]) ?>

    <?php else: ?>

        <div class="flex flex-col gap-4">
            <?php foreach ($feed['posts'] as $post): ?>
                <article class="rounded-2xl border border-gray-200 bg-white px-4 py-4 sm:px-5 shadow-sm" data-instructor-post="<?= (int) $post['id'] ?>">
                    <p class="flex items-center gap-2 text-xs text-[#767676]">
                        <?php if (($post['avatar'] ?? null) !== null): ?>
                            <?= view('components/avatar', ['person' => $post['avatar']['person'], 'size' => 'xs', 'interactive' => $post['avatar']['interactive'], 'decorative' => true], ['saveData' => false]) ?>
                        <?php endif; ?>
                        <span><span class="font-semibold text-[#3D3D3D]"><?= esc($post['sender_name']) ?></span>
                        · <?= esc(date('M j, Y g:i A', strtotime((string) $post['created_at']))) ?></span>
                    </p>
                    <p class="mt-2 break-words text-sm leading-relaxed text-[#3D3D3D]"><?= nl2br(esc($post['body']), false) ?></p>
                </article>
            <?php endforeach; ?>
        </div>

    <?php endif; ?>

</div>
