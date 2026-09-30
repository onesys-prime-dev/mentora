<?php
/**
 * What's waiting on the instructor.
 *
 * Grouped by assessment rather than by submission on purpose: grading is a
 * batch job, so "Quiz 2 — 24 papers, oldest waiting 9 days" is the unit of
 * work. A flat list of 60 individual submissions would be noise.
 *
 * Ordered by how long the oldest submission has sat unmarked, since that is
 * what students actually feel. Data: DashboardService::getGradingQueueData(),
 * built from AssessmentOverviewService — the same source behind the
 * Assessments tab's "To Grade" metric, so the two can't disagree.
 */
$userRole = esc($user->getGroups()[0]);

$queue = $data['queue'] ?? [];

/**
 * What kind of work is waiting — a quiz reads differently from a lab or an
 * essay, and marking them takes different effort, so the row leads with type.
 * Falls back to the quiz glyph for any type the map doesn't know.
 */
$typeIcons = [
    'quiz'       => 'tasks',
    'exam'       => 'tasks',
    'lab'        => 'code',
    'coding'     => 'code',
    'paper'      => 'lessons',
    'project'    => 'lessons',
    'discussion' => 'message',
];

/** A week unmarked is the point where students start asking about it. */
$waitStyle = function (int $days): array {
    if ($days >= 7) {
        return ['bubble' => 'bg-red-600',   'chip' => 'bg-red-500/15 text-red-700'];
    }

    if ($days >= 3) {
        return ['bubble' => 'bg-amber-600', 'chip' => 'bg-amber-500/20 text-amber-700'];
    }

    return ['bubble' => 'bg-blue-800', 'chip' => 'bg-blue-500/15 text-blue-800'];
};

$totalUngraded = array_sum(array_column($queue, 'ungraded'));
?>

<!-- grading queue -->
<div class="h-full min-h-0 flex flex-col bg-white/20 backdrop-blur-xl border border-white/30 shadow-xl rounded-3xl overflow-clip">

    <div class="shrink-0 flex items-center justify-between px-6 py-3 bg-black/10">
        <span class="text-xl font-display">Grading Queue</span>

        <?php if ($queue !== []): ?>
            <span class="text-xs text-gray-700">
                <?= esc($totalUngraded) ?> to grade
            </span>
        <?php endif; ?>
    </div>

    <div class="flex-1 min-h-0 overflow-y-auto scroll-area px-6 py-4">

        <?php if ($queue === []): ?>

            <div class="h-full flex flex-col justify-center items-center gap-2 text-center">

                <div class="w-12 h-12 flex justify-center items-center rounded-full bg-white/40 border border-black/5">
                    <?= icon('tasks', 'w-6 h-6 text-gray-500') ?>
                </div>

                <span class="text-sm font-medium text-gray-700">Nothing to grade</span>
                <span class="text-xs text-gray-600 leading-snug">
                    Submissions waiting on you will collect here.
                </span>

            </div>

        <?php else: ?>

            <div class="flex flex-col">

                <?php foreach ($queue as $index => $item): ?>

                    <?php if ($index > 0): ?>
                        <div class="h-px -mx-3 bg-black/5" aria-hidden="true"></div>
                    <?php endif; ?>

                    <?php
                    $days = (int) $item['waiting_days'];
                    $style = $waitStyle($days);

                    $typeIcon = $typeIcons[$item['type'] ?? ''] ?? 'tasks';

                    $submitted = max(1, (int) $item['submitted']);
                    $gradedPct = (int) round(($submitted - $item['ungraded']) / $submitted * 100);

                    $waitLabel = $days <= 1 ? 'Since yesterday' : $days . ' days waiting';
                    ?>

                    <a
                        class="group flex items-center gap-3 py-3 px-3 -mx-3 rounded-2xl transition-colors duration-150 hover:bg-white/45"
                        href="<?= site_url($userRole . '/courses/' . $item['offering_id'] . '/' . $item['slug'] . '/grades?assessment=' . $item['assessment_id'] . '&grade=1') ?>">

                        <div class="w-11 h-11 shrink-0 flex justify-center items-center <?= $style['bubble'] ?> rounded-full transition-transform duration-150 group-hover:scale-110">
                            <?= icon($typeIcon, 'w-5 h-5 text-white') ?>
                        </div>

                        <div class="flex flex-col min-w-0 flex-1 gap-1">
                            <div class="flex items-center gap-2 min-w-0">
                                <div class="flex gap-1 text-sm min-w-0 transition-colors duration-150 group-hover:text-blue-900">
                                    <span class="font-bold shrink-0"><?= esc($item['course_code']) ?></span>
                                    <span class="shrink-0">-</span>
                                    <span class="font-bold shrink-0"><?= esc($item['section_code']) ?></span>
                                    <span class="shrink-0">-</span>
                                    <span class="truncate"><?= esc($item['title']) ?></span>
                                </div>

                                <!--
                                    The count moved off the bubble to make room for the
                                    type glyph. Neutral on purpose: the bubble and the
                                    wait chip already carry urgency, so this pill says
                                    how MUCH work, not how late it is.
                                -->
                                <span class="shrink-0 px-2 py-0.5 rounded-full text-[10px] font-bold bg-gray-800/85 text-white">
                                    <?= esc($item['ungraded']) ?>
                                </span>
                            </div>

                            <div class="flex items-center gap-2">
                                <div class="h-1.5 flex-1 bg-black/10 rounded-full overflow-clip">
                                    <div
                                        class="h-full bg-emerald-600 rounded-full"
                                        style="width: <?= $gradedPct ?>%;"></div>
                                </div>

                                <span class="shrink-0 text-[11px] text-gray-600">
                                    <?= esc($submitted - $item['ungraded']) ?>/<?= esc($submitted) ?> graded
                                </span>
                            </div>
                        </div>

                        <span class="shrink-0 px-2.5 py-1 rounded-full text-[11px] font-medium <?= $style['chip'] ?>">
                            <?= esc($waitLabel) ?>
                        </span>

                    </a>

                <?php endforeach; ?>

            </div>

        <?php endif; ?>

    </div>

</div>
