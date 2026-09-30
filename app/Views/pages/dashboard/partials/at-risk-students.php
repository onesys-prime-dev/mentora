<?php
/**
 * Exception-finding, not reporting.
 *
 * An instructor cannot read 120 rows every morning, so this widget's whole
 * job is to turn the roster into the handful of names worth an email today.
 * Risk comes from learning_completions: a completion rate well under the
 * section's own cohort, or no activity at all for a stretch of days.
 *
 * Data: DashboardService::getAtRiskStudentsData(). A row links into that
 * student's Gradebook roster entry (course offering's Grades tab, scrolled
 * to and briefly highlighted).
 */
$userRole = esc($user->getGroups()[0]);

$students = $data['students'] ?? [];

$reasonStyles = [
    'inactive' => ['chip' => 'bg-red-500/15 text-red-700',     'label' => 'Inactive',   'bar' => 'bg-red-600'],
    'behind'   => ['chip' => 'bg-amber-500/20 text-amber-700', 'label' => 'Behind',     'bar' => 'bg-amber-600'],
];
?>

<!-- at-risk students -->
<div class="h-full min-h-0 flex flex-col bg-white/20 backdrop-blur-xl border border-white/30 shadow-xl rounded-3xl overflow-clip">

    <div class="shrink-0 flex items-center justify-between px-6 py-3 bg-black/10">
        <span class="text-xl font-display">Needs Your Attention</span>

        <?php if ($students !== []): ?>
            <span class="px-2.5 py-1 rounded-full text-[11px] font-medium bg-red-500/15 text-red-700">
                <?= count($students) ?> students
            </span>
        <?php endif; ?>
    </div>

    <div class="flex-1 min-h-0 overflow-y-auto scroll-area px-6 py-4">

        <?php if ($students === []): ?>

            <div class="h-full flex flex-col justify-center items-center gap-1 text-center py-6">
                <span class="text-sm font-medium text-gray-700">Everyone's on track</span>
                <span class="text-xs text-gray-600">Students who fall behind their cohort will surface here.</span>
            </div>

        <?php else: ?>

            <div class="flex flex-col">

                <?php foreach ($students as $index => $student): ?>

                    <?php if ($index > 0): ?>
                        <div class="h-px -mx-3 bg-black/5" aria-hidden="true"></div>
                    <?php endif; ?>

                    <?php $style = $reasonStyles[$student['reason']] ?? $reasonStyles['behind']; ?>

                    <a
                        class="group flex items-center gap-3 py-3 px-3 -mx-3 rounded-2xl transition-colors duration-150 hover:bg-white/45"
                        href="<?= site_url($userRole . '/courses/' . $student['offering_id'] . '/' . $student['slug'] . '/grades?roster=1&student=' . $student['student_id']) ?>">

                        <img
                            class="w-10 h-10 shrink-0 rounded-full object-cover transition-transform duration-150 group-hover:scale-110"
                            src="<?= base_url('assets/images/placeholder/profile.png') ?>"
                            alt="">

                        <div class="flex flex-col min-w-0 flex-1 gap-1">
                            <div class="flex items-center gap-2 min-w-0">
                                <span class="text-sm font-bold truncate transition-colors duration-150 group-hover:text-blue-900">
                                    <?= esc($student['name']) ?>
                                </span>

                                <span class="shrink-0 px-2 py-0.5 rounded-full text-[10px] font-medium <?= $style['chip'] ?>">
                                    <?= esc($style['label']) ?>
                                </span>
                            </div>

                            <div class="flex items-center gap-2 text-xs text-gray-700 min-w-0">
                                <span class="truncate"><?= esc($student['section']) ?></span>
                                <span class="shrink-0">·</span>
                                <span class="shrink-0">Last seen <?= esc($student['last_active']) ?></span>
                            </div>
                        </div>

                        <div class="shrink-0 w-20 flex flex-col gap-1">
                            <span class="text-[11px] text-gray-600 text-right"><?= esc($student['progress']) ?>%</span>

                            <div class="h-1.5 bg-black/10 rounded-full overflow-clip">
                                <div
                                    class="h-full <?= $style['bar'] ?> rounded-full"
                                    style="width: <?= esc($student['progress']) ?>%;"></div>
                            </div>
                        </div>

                    </a>

                <?php endforeach; ?>

            </div>

        <?php endif; ?>

    </div>

</div>
