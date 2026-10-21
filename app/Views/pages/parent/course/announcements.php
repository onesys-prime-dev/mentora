<?php
/**
 * The Announcements tab for a parent: a plain feed of what the instructor
 * posted. No replies, reactions, attachments, composer or menus.
 *
 * @var list<array<string, mixed>> $announcements rows of AnnouncementModel::retrieveForOffering()
 * @var bool                       $truncated     whether there are older posts than the ones shown
 * @var int                        $limit         how many are shown at most
 */
?>

<div class="p-10 flex flex-col gap-8">

    <?= view('components/page-header', ['icon' => 'announcement', 'title' => 'Announcements'], ['saveData' => false]) ?>

    <?php if ($announcements === []): ?>

        <?= view('components/empty-state', [
            'icon'  => 'announcement',
            'title' => 'No announcements yet',
            'text'  => 'What the instructor posts for this course appears here.',
        ], ['saveData' => false]) ?>

    <?php else: ?>

        <div class="flex flex-col gap-4" data-parent-announcements>
            <?php foreach ($announcements as $announcement): ?>
                <article
                    class="scroll-mt-6 rounded-2xl border <?= $announcement['is_pinned'] ? 'border-brand/30' : 'border-gray-200' ?> bg-white shadow-sm"
                    data-announcement-card
                    data-announcement-id="<?= (int) $announcement['id'] ?>">

                    <?php if ($announcement['is_pinned']): ?>
                        <div class="flex items-center gap-1.5 rounded-t-2xl bg-brand/5 px-5 py-1.5 text-xs font-bold text-brand">
                            <?= icon('pin', 'h-3 w-3 text-current') ?>
                            Pinned
                        </div>
                    <?php endif; ?>

                    <div class="flex flex-col gap-2 px-5 py-4">
                        <h3 class="text-base font-bold text-[#1A1C1E]"><?= esc($announcement['subject']) ?></h3>

                        <p class="text-xs text-[#767676]">
                            <span class="font-semibold text-[#3D3D3D]"><?= esc(trim((string) $announcement['author_name'])) ?></span>
                            · <?= esc(date('M j, Y g:i A', strtotime((string) $announcement['created_at']))) ?>
                        </p>

                        <div class="flex flex-col gap-2 text-sm leading-relaxed text-[#3D3D3D]">
                            <?= render_announcement_message($announcement['message']) ?>
                        </div>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>

        <?php if ($truncated): ?>
            <p class="text-xs text-[#646464]">Showing the <?= (int) $limit ?> most recent.</p>
        <?php endif; ?>

    <?php endif; ?>

</div>
