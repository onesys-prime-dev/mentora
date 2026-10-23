<?php
/** Same data as the shared audit-highlights partial (DashboardService::getAuditHighlightsData()). */
$userRole = esc(active_role() ?: ($user->getGroups()[0] ?? ''));

$entries = $data['entries'] ?? [];
?>

<!-- audit highlights (chairperson) -->
<div class="flex flex-col bg-white rounded-3xl shadow-md ring-1 ring-black/5 border-t-4 border-t-[#1E8A4C] overflow-clip h-full">

    <div class="flex items-center gap-2.5 px-5 py-4 border-b border-[#F1F3F8] bg-[#F8FAFF]">
        <?= icon('check', 'w-[17px] h-[17px] text-[#0040A6]') ?>
        <span class="text-[15px] font-bold text-[#0A0F1E]">Your Recent Actions</span>
    </div>

    <?php if ($entries === []): ?>

        <div class="flex flex-col items-center justify-center gap-1 text-center py-10 flex-1">
            <span class="text-sm font-medium text-[#2A3142]">No activity yet</span>
            <span class="text-xs text-[#6B7488]">Changes you make across your program will show up here.</span>
        </div>

    <?php else: ?>

        <div class="flex flex-col flex-1 min-h-0 overflow-y-auto scroll-area">

            <?php foreach ($entries as $entry): ?>

                <?php if ($entry['offering_id'] !== null && $entry['slug'] !== null): ?>

                    <a
                        class="flex items-center justify-between gap-3 px-5 py-3.5 border-b border-[#F6F7FA] last:border-b-0 transition-colors hover:bg-[#F8FAFF]"
                        href="<?= site_url($userRole . '/courses/' . $entry['offering_id'] . '/' . $entry['slug']) ?>">
                        <span class="text-[13px] text-[#1A1C1E] truncate">
                            <?= esc($entry['action']) ?> · <?= esc($entry['course_code'] . ' ' . $entry['section_code']) ?>
                        </span>
                        <span class="text-xs text-[#9AA3B8] shrink-0 whitespace-nowrap"><?= esc($entry['when']) ?></span>
                    </a>

                <?php else: ?>

                    <div class="flex items-center justify-between gap-3 px-5 py-3.5 border-b border-[#F6F7FA] last:border-b-0">
                        <span class="text-[13px] text-[#1A1C1E]"><?= esc($entry['action']) ?></span>
                        <span class="text-xs text-[#9AA3B8] shrink-0 whitespace-nowrap"><?= esc($entry['when']) ?></span>
                    </div>

                <?php endif; ?>

            <?php endforeach; ?>

        </div>

    <?php endif; ?>

</div>
