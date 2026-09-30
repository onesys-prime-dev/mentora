<?php
/**
 * One scannable column sorted by urgency — people read deadlines down,
 * not across, and colour is what separates "due tonight" from "due in
 * three weeks" at a glance.
 *
 * Data: DashboardService::getUpcomingTasksData(), merged across every
 * enrolled offering via AssessmentOverviewService. A row links to that
 * assessment; a task already submitted/graded keeps its due-date urgency
 * chip (still useful context) and gains a second status pill rather than
 * losing the urgency information.
 */
$userRole = esc($user->getGroups()[0]);

$tasks = $data['tasks'] ?? [];

$urgencyStyles = [
    'overdue' => ['bubble' => 'bg-red-600',     'chip' => 'bg-red-500/15 text-red-700',       'label' => 'Overdue'],
    'today'   => ['bubble' => 'bg-red-600',     'chip' => 'bg-red-500/15 text-red-700',       'label' => 'Due today'],
    'soon'    => ['bubble' => 'bg-amber-600',   'chip' => 'bg-amber-500/20 text-amber-700',   'label' => 'This week'],
    'later'   => ['bubble' => 'bg-blue-800',    'chip' => 'bg-blue-500/15 text-blue-800',     'label' => 'Upcoming'],
];

$statusStyles = [
    'graded'    => ['chip' => 'bg-emerald-500/15 text-emerald-700', 'label' => 'Graded'],
    'submitted' => ['chip' => 'bg-blue-500/15 text-blue-800',       'label' => 'Submitted'],
];
?>

<!-- upcoming tasks card -->
<div class="h-full min-h-0 flex flex-col bg-white/20 backdrop-blur-xl border border-white/30 shadow-xl rounded-3xl overflow-clip">

    <div class="shrink-0 flex items-center justify-between px-6 py-3 bg-black/10">
        <span class="text-xl font-display">Upcoming Tasks</span>

        <span class="text-xs text-gray-700">
            <?= count($tasks) ?> due
        </span>
    </div>

    <div class="flex-1 min-h-0 overflow-y-auto scroll-area px-6 py-4">

        <?php if ($tasks === []): ?>

            <div class="h-full flex flex-col justify-center items-center gap-1 text-center py-6">
                <span class="text-sm font-medium text-gray-700">Nothing due right now</span>
                <span class="text-xs text-gray-600">New tasks from your courses will show up here.</span>
            </div>

        <?php else: ?>

            <div class="flex flex-col">

                <?php foreach ($tasks as $index => $task): ?>

                    <?php if ($index > 0): ?>
                        <div class="h-px -mx-3 bg-black/5" aria-hidden="true"></div>
                    <?php endif; ?>

                    <?php
                    $style = $urgencyStyles[$task['urgency']] ?? $urgencyStyles['later'];
                    $statusStyle = $statusStyles[$task['status'] ?? ''] ?? null;
                    ?>

                    <a
                        class="group flex items-center gap-3 py-3 px-3 -mx-3 rounded-2xl transition-colors duration-150 hover:bg-white/45"
                        href="<?= site_url($userRole . '/courses/' . $task['offering_id'] . '/' . $task['slug'] . '/assessments?assessment=' . $task['id'] . '&view=1') ?>">

                        <div class="w-11 h-11 shrink-0 flex justify-center items-center <?= $style['bubble'] ?> rounded-full transition-transform duration-150 group-hover:scale-110">
                            <?= icon('code', 'w-5 h-5 text-white') ?>
                        </div>

                        <div class="flex flex-col min-w-0 flex-1">
                            <div class="flex items-center gap-1 text-sm min-w-0 transition-colors duration-150 group-hover:text-blue-900">
                                <span class="font-bold shrink-0"><?= esc($task['code']) ?></span>
                                <span class="shrink-0">-</span>
                                <span class="truncate"><?= esc($task['title']) ?></span>

                                <?php if ($statusStyle): ?>
                                    <span class="shrink-0 px-2 py-0.5 rounded-full text-[10px] font-semibold <?= $statusStyle['chip'] ?>">
                                        <?= esc($statusStyle['label']) ?>
                                    </span>
                                <?php endif; ?>
                            </div>

                            <div class="flex gap-1 text-xs text-gray-700">
                                <span class="font-bold">Due:</span>
                                <span><?= esc($task['due']) ?></span>
                            </div>
                        </div>

                        <span class="shrink-0 px-2.5 py-1 rounded-full text-[11px] font-medium <?= $style['chip'] ?>">
                            <?= esc($style['label']) ?>
                        </span>

                    </a>

                <?php endforeach; ?>

            </div>

        <?php endif; ?>

    </div>

</div>
