<?php
/**
 * "Did anything change since I last looked?" — so rows lead with the
 * headline and carry a posted-at timestamp, not a due date.
 */
$userRole = esc($user->getGroups()[0]);

$announcements = $data['announcements'] ?? [];
?>

<!-- announcements -->
<div class="h-full min-h-0 flex flex-col bg-white/20 backdrop-blur-xl border border-white/30 shadow-xl rounded-3xl overflow-clip">

    <div class="shrink-0 flex items-center justify-between px-6 py-3 bg-black/10">
        <span class="text-xl font-display">Announcements</span>
    </div>

    <div class="flex-1 min-h-0 overflow-y-auto scroll-area px-6 py-4">

        <?php if ($announcements === []): ?>

            <div class="h-full flex flex-col justify-center items-center gap-1 text-center py-6">
                <span class="text-sm font-medium text-gray-700">No announcements</span>
                <span class="text-xs text-gray-600">Posts from your instructors will appear here.</span>
            </div>

        <?php else: ?>

            <div class="flex flex-col">

                <?php foreach ($announcements as $index => $announcement): ?>

                    <?php if ($index > 0): ?>
                        <div class="h-px -mx-3 bg-black/5" aria-hidden="true"></div>
                    <?php endif; ?>

                    <a
                        href="<?= site_url($userRole . '/courses/' . $announcement['offering_id'] . '/' . $announcement['slug'] . '/announcements') ?>"
                        class="group flex items-center gap-3 py-3 px-3 -mx-3 rounded-2xl cursor-pointer transition-colors duration-150 hover:bg-white/45">

                        <div class="relative shrink-0">
                            <div class="w-9 h-9 flex justify-center items-center bg-blue-800 rounded-full transition-transform duration-150 group-hover:scale-110">
                                <?= icon('message', 'w-5 h-5 text-white') ?>
                            </div>
                        </div>

                        <div class="flex flex-col min-w-0 flex-1">
                            <span class="text-sm font-medium leading-tight truncate transition-colors duration-150 group-hover:text-blue-900">
                                <?= esc($announcement['title']) ?>
                            </span>

                            <div class="flex gap-1 text-xs text-gray-700 min-w-0">
                                <span class="font-bold shrink-0"><?= esc($announcement['code']) ?></span>
                                <span class="shrink-0">-</span>
                                <span class="truncate"><?= esc($announcement['author']) ?></span>
                            </div>
                        </div>

                        <span class="shrink-0 text-xs text-gray-600">
                            <?= esc($announcement['posted']) ?>
                        </span>

                    </a>

                <?php endforeach; ?>

            </div>

        <?php endif; ?>

    </div>

</div>
