<?php
/**
 * R3's "audit-log highlights": the chairperson's own recent actions across
 * their program (see DashboardService::getAuditHighlightsData()) — the same
 * course.chairperson_action entries ChairpersonCourseAudit writes for every
 * mutating request.
 */
$userRole = esc(active_role() ?: ($user->getGroups()[0] ?? ''));

$entries = $data['entries'] ?? [];
?>

<!-- audit highlights -->
<div class="h-full min-h-0 flex flex-col bg-white/20 backdrop-blur-xl border border-white/30 shadow-xl rounded-3xl overflow-clip">

    <div class="shrink-0 px-6 py-3 bg-black/10">
        <span class="text-xl font-display">Recent Activity</span>
    </div>

    <div class="flex-1 min-h-0 overflow-y-auto scroll-area px-6 py-4">

        <?php if ($entries === []): ?>

            <div class="h-full flex flex-col justify-center items-center gap-1 text-center py-6">
                <span class="text-sm font-medium text-gray-700">No activity yet</span>
                <span class="text-xs text-gray-600">Changes you make across your program will show up here.</span>
            </div>

        <?php else: ?>

            <ul class="flex flex-col gap-3">

                <?php foreach ($entries as $entry): ?>
                    <li>
                        <?php if ($entry['offering_id'] !== null && $entry['slug'] !== null): ?>
                            <a
                                class="group flex items-center justify-between gap-3 p-3 bg-white/30 border border-black/5 shadow-md rounded-2xl transition hover:bg-white/50"
                                href="<?= site_url($userRole . '/courses/' . $entry['offering_id'] . '/' . $entry['slug']) ?>">
                                <div class="flex flex-col min-w-0">
                                    <span class="text-sm font-medium truncate"><?= esc($entry['action']) ?></span>
                                    <span class="text-xs text-gray-600">
                                        <?= esc($entry['course_code'] . ' - ' . $entry['section_code']) ?> · <?= esc($entry['when']) ?>
                                    </span>
                                </div>
                            </a>
                        <?php else: ?>
                            <div class="flex flex-col gap-0.5 p-3">
                                <span class="text-sm font-medium"><?= esc($entry['action']) ?></span>
                                <span class="text-xs text-gray-600"><?= esc($entry['when']) ?></span>
                            </div>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>

            </ul>

        <?php endif; ?>

    </div>

</div>
