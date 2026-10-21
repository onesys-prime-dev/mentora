<?php
/**
 * The dean's latest announcements to the department (the ones still showing), with a way to post another.
 * Data: DeanDashboardService::overview()['announcements'] as `posts`, rows from DepartmentAnnouncementService::listForDean().
 *
 * @var array{posts: list<array<string, mixed>>} $data
 */
$posts = $data['posts'] ?? [];
?>

<!-- department announcements (dean) -->
<div class="flex flex-col bg-white rounded-3xl shadow-sm overflow-clip h-full" data-widget="announcements">

    <div class="flex items-center justify-between gap-3 px-5 py-4 border-b border-[#F1F3F8]">
        <div class="flex items-center gap-2.5">
            <?= icon('message', 'w-[17px] h-[17px] text-[#0040A6]') ?>
            <h2 class="text-[15px] font-bold text-[#0A0F1E]">Department announcements</h2>
        </div>

        <a href="<?= esc(site_url('dean/announcements'), 'attr') ?>" class="text-xs font-semibold text-brand hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/50">Manage</a>
    </div>

    <?php if ($posts === []): ?>

        <div class="flex flex-col items-center justify-center gap-1 text-center py-10 flex-1" data-announcements-empty>
            <span class="text-sm font-medium text-[#2A3142]">Nothing announced</span>
            <span class="text-xs text-[#6B7488]">Tell your department, a sub-department or a program something.</span>
            <a href="<?= esc(site_url('dean/announcements'), 'attr') ?>" class="mt-2 text-xs font-semibold text-brand hover:underline">New announcement</a>
        </div>

    <?php else: ?>

        <div class="flex flex-col flex-1 min-h-0 overflow-y-auto scroll-area">
            <?php foreach ($posts as $post): ?>
                <a href="<?= esc(site_url('dean/announcements'), 'attr') ?>" class="flex flex-col gap-0.5 px-5 py-3.5 border-b border-[#F6F7FA] last:border-b-0 transition-colors hover:bg-[#F8FAFF] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-brand/50" data-announcement>
                    <span class="text-[13px] font-semibold text-[#1A1C1E] truncate"><?= esc($post['title']) ?></span>
                    <span class="text-xs text-[#6B7488]"><?= esc($post['scope_label']) ?> · <?= esc($post['audience_label']) ?> · <?= $post['ends_on'] ? 'until ' . esc(date('M j', strtotime((string) $post['ends_on']))) : 'no end date' ?></span>
                </a>
            <?php endforeach; ?>
        </div>

    <?php endif; ?>

</div>
