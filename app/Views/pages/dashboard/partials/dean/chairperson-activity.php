<?php
/**
 * What chairpersons changed lately in courses they do not teach, newest first, each opening the course
 * (read-only for the dean), with how many the dean has yet to review. Data:
 * DeanDashboardService::overview()['chairperson_activity'].
 *
 * @var array{to_review: int, entries: list<array<string, mixed>>} $data
 */
$entries = $data['entries'] ?? [];
?>

<!-- chairperson activity (dean) -->
<div class="flex flex-col bg-white rounded-3xl shadow-sm overflow-clip h-full" data-widget="chairperson-activity">

    <div class="flex items-center justify-between gap-3 px-5 py-4 border-b border-[#F1F3F8]">
        <div class="flex items-center gap-2.5">
            <?= icon('tasks', 'w-[17px] h-[17px] text-[#0040A6]') ?>
            <h2 class="text-[15px] font-bold text-[#0A0F1E]">Chairperson activity</h2>
        </div>

        <a href="<?= esc(site_url('dean/chairperson-review'), 'attr') ?>" class="text-xs font-semibold text-brand hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/50">
            <?= (int) $data['to_review'] > 0 ? (int) $data['to_review'] . ' to review' : 'Review' ?>
        </a>
    </div>

    <?php if ($entries === []): ?>

        <div class="flex flex-col items-center justify-center gap-1 text-center py-10 flex-1" data-activity-empty>
            <span class="text-sm font-medium text-[#2A3142]">No changes yet</span>
            <span class="text-xs text-[#6B7488]">When a chairperson changes a course they do not teach, it appears here.</span>
        </div>

    <?php else: ?>

        <div class="flex flex-col flex-1 min-h-0 overflow-y-auto scroll-area">
            <?php foreach ($entries as $entry): ?>
                <?php $href = $entry['slug'] !== null ? site_url('dean/courses/' . (int) $entry['offering_id'] . '/' . $entry['slug']) : site_url('dean/chairperson-review'); ?>

                <a href="<?= esc($href, 'attr') ?>" class="flex flex-col gap-0.5 px-5 py-3.5 border-b border-[#F6F7FA] last:border-b-0 transition-colors hover:bg-[#F8FAFF] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-brand/50" data-activity-entry>
                    <span class="text-[13px] font-semibold text-[#1A1C1E] truncate"><?= esc($entry['description']) ?><?= $entry['course_code'] !== null ? ' · ' . esc(trim($entry['course_code'] . ' ' . $entry['section_code'])) : '' ?></span>
                    <span class="text-xs text-[#6B7488]"><?= esc((string) ($entry['actor'] ?? '')) ?> · <?= esc(date('M j, g:i a', strtotime((string) $entry['created_at']))) ?><?= $entry['reviewed_at'] !== null ? ' · reviewed' : '' ?></span>
                </a>
            <?php endforeach; ?>
        </div>

    <?php endif; ?>

</div>
