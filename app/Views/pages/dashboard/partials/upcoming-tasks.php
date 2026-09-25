<?php
/**
 * One scannable column sorted by urgency — people read deadlines down,
 * not across, and colour is what separates "due tonight" from "due in
 * three weeks" at a glance.
 *
 * Placeholder rows until the assessments table exists; swap for $data['tasks'].
 */
$tasks = $data['tasks'] ?? [
    ['code' => 'IT101', 'title' => 'M1 - Coding Activity',   'due' => 'Today - 11:59PM',          'urgency' => 'today'],
    ['code' => 'IT101', 'title' => 'M1 - Reflection Paper',  'due' => 'Jul 11, 2026 - 11:59PM',   'urgency' => 'soon'],
    ['code' => 'IT102', 'title' => 'Lab Exercise 3',         'due' => 'Jul 13, 2026 - 5:00PM',    'urgency' => 'soon'],
    ['code' => 'IT102', 'title' => 'M2 - Case Study',        'due' => 'Jul 24, 2026 - 11:59PM',   'urgency' => 'later'],
    ['code' => 'IT101', 'title' => 'Final Project Proposal', 'due' => 'Aug 02, 2026 - 11:59PM',   'urgency' => 'later'],
];

$urgencyStyles = [
    'overdue' => ['bubble' => 'bg-red-600',     'chip' => 'bg-red-500/15 text-red-700',       'label' => 'Overdue'],
    'today'   => ['bubble' => 'bg-red-600',     'chip' => 'bg-red-500/15 text-red-700',       'label' => 'Due today'],
    'soon'    => ['bubble' => 'bg-amber-600',   'chip' => 'bg-amber-500/20 text-amber-700',   'label' => 'This week'],
    'later'   => ['bubble' => 'bg-blue-800',    'chip' => 'bg-blue-500/15 text-blue-800',     'label' => 'Upcoming'],
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

                    <?php $style = $urgencyStyles[$task['urgency']] ?? $urgencyStyles['later']; ?>

                    <div class="group flex items-center gap-3 py-3 px-3 -mx-3 rounded-2xl cursor-pointer transition-colors duration-150 hover:bg-white/45">

                        <div class="w-11 h-11 shrink-0 flex justify-center items-center <?= $style['bubble'] ?> rounded-full transition-transform duration-150 group-hover:scale-110">
                            <?= icon('code', 'w-5 h-5 text-white') ?>
                        </div>

                        <div class="flex flex-col min-w-0 flex-1">
                            <div class="flex gap-1 text-sm transition-colors duration-150 group-hover:text-blue-900">
                                <span class="font-bold shrink-0"><?= esc($task['code']) ?></span>
                                <span class="shrink-0">-</span>
                                <span class="truncate"><?= esc($task['title']) ?></span>
                            </div>

                            <div class="flex gap-1 text-xs text-gray-700">
                                <span class="font-bold">Due:</span>
                                <span><?= esc($task['due']) ?></span>
                            </div>
                        </div>

                        <span class="shrink-0 px-2.5 py-1 rounded-full text-[11px] font-medium <?= $style['chip'] ?>">
                            <?= esc($style['label']) ?>
                        </span>

                    </div>

                <?php endforeach; ?>

            </div>

        <?php endif; ?>

    </div>

</div>
