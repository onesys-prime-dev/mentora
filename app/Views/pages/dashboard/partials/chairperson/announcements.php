<?php
/** Same data as the shared announcements partial (DashboardService::getAnnouncementsData()). */
$userRole = esc(active_role() ?: ($user->getGroups()[0] ?? ''));

$announcements = $data['announcements'] ?? [];
?>

<!-- announcements (chairperson) -->
<div class="flex flex-col bg-white rounded-3xl shadow-sm overflow-clip h-full">

    <div class="flex items-center gap-2.5 px-5 py-4 border-b border-[#F1F3F8]">
        <?= icon('message', 'w-[17px] h-[17px] text-[#0040A6]') ?>
        <span class="text-[15px] font-bold text-[#0A0F1E]">Announcements</span>
    </div>

    <?php if ($announcements === []): ?>

        <div class="flex flex-col items-center justify-center gap-1 text-center py-10 flex-1">
            <span class="text-sm font-medium text-[#2A3142]">No announcements</span>
            <span class="text-xs text-[#6B7488]">Posts from your instructors will appear here.</span>
        </div>

    <?php else: ?>

        <div class="flex flex-col flex-1 min-h-0 overflow-y-auto scroll-area">

            <?php foreach ($announcements as $announcement): ?>

                <a
                    href="<?= site_url($userRole . '/courses/' . $announcement['offering_id'] . '/' . $announcement['slug'] . '/announcements') ?>"
                    class="flex flex-col gap-0.5 px-5 py-3.5 border-b border-[#F6F7FA] last:border-b-0 transition-colors hover:bg-[#F8FAFF]">

                    <span class="text-[13px] font-semibold text-[#1A1C1E] truncate"><?= esc($announcement['title']) ?></span>
                    <span class="text-xs text-[#6B7488]">
                        <?= esc($announcement['code']) ?> · <?= esc($announcement['author']) ?> · <?= esc($announcement['posted']) ?>
                    </span>

                </a>

            <?php endforeach; ?>

        </div>

    <?php endif; ?>

</div>
