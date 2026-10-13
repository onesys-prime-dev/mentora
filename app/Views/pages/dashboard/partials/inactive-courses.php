<?php
/**
 * R3's third "needs attention" signal: courses in the chair's program with
 * no module activity for 14+ days (see
 * DashboardService::getInactiveCoursesData()). An instructor who's gone
 * quiet on a course is exactly the kind of thing a chairperson, not a
 * student or that instructor themselves, is positioned to notice.
 */
$userRole = esc(active_role() ?: ($user->getGroups()[0] ?? ''));

$courses = $data['courses'] ?? [];
?>

<!-- inactive courses -->
<div class="h-full min-h-0 flex flex-col bg-white/20 backdrop-blur-xl border border-white/30 shadow-xl rounded-3xl overflow-clip">

    <div class="shrink-0 flex items-center justify-between px-6 py-3 bg-black/10">
        <span class="text-xl font-display">Needs a Look</span>

        <a
            class="text-xs text-blue-800 font-medium hover:underline"
            href="<?= site_url($userRole . '/courses') ?>">
            View all
        </a>
    </div>

    <div class="flex-1 min-h-0 overflow-y-auto scroll-area px-6 py-4">

        <?php if ($courses === []): ?>

            <div class="h-full flex flex-col justify-center items-center gap-1 text-center py-6">
                <span class="text-sm font-medium text-gray-700">Everything's active</span>
                <span class="text-xs text-gray-600">Courses with no recent activity will appear here.</span>
            </div>

        <?php else: ?>

            <div class="flex flex-col gap-3">

                <?php foreach ($courses as $course): ?>

                    <a
                        class="group flex items-center justify-between gap-3 p-3 bg-white/30 border border-black/5 shadow-md rounded-2xl transition hover:bg-white/50"
                        href="<?= site_url($userRole . '/courses/' . $course['offering_id'] . '/' . $course['slug']) ?>">

                        <div class="flex flex-col min-w-0">
                            <div class="flex gap-1 text-xs text-gray-600">
                                <span class="font-bold"><?= esc($course['course_code']) ?></span>
                                <span>-</span>
                                <span class="truncate"><?= esc($course['section_code']) ?></span>
                            </div>
                            <span class="text-xs text-amber-700 font-medium">
                                Last activity: <?= esc($course['last_activity']) ?>
                            </span>
                        </div>

                        <?= icon('clock', 'w-4 h-4 text-amber-700 shrink-0') ?>

                    </a>

                <?php endforeach; ?>

            </div>

        <?php endif; ?>

    </div>

</div>
