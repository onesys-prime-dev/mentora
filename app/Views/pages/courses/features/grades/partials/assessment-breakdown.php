<?php
/**
 * Grouped by assessment type (Quizzes, Assignments) rather than one flat
 * list — a student scanning their grades thinks in those buckets, and it's
 * the same grouping the instructor's list uses, so the two views describe
 * the same structure two ways.
 *
 * Rows come from GradeService::buildStudentGrades(): the same state and
 * counted score the Assessments tab shows. Clicking a row opens that
 * assessment (resources/js/pages/courses/features/grades/index.js).
 */
$typeHeadings = [
    'Quiz'       => 'Quizzes',
    'Assignment' => 'Assignments',
];

$groups = [];

foreach (($assessments ?? []) as $assessment) {
    $groups[$assessment['assessment_type']][] = $assessment;
}

$formatPoints = static fn (float $points) => (float) (int) $points === $points ? (string) (int) $points : (string) round($points, 2);

$chipClasses = [
    'success' => 'bg-[#E8F5EC] text-[#2E7D4F]',
    'warning' => 'bg-[#FFF1D6] text-[#946200]',
    'info'    => 'bg-brand/10 text-brand',
    'danger'  => 'bg-[#FDEDEC] text-[#C33939]',
    'muted'   => 'bg-gray-100 text-gray-500',
    'neutral' => 'bg-gray-100 text-gray-500',
];
?>

<div class="flex flex-col gap-3 w-full">

    <h3 class="text-xl font-bold text-brand">Assessments</h3>

    <?php if ($groups === []): ?>

        <div class="px-8 py-10 flex flex-col items-center justify-center gap-2 text-center rounded-2xl border-[1px] border-[#A9A9A9]/50">

            <?= icon('no-score', 'h-8 w-8 opacity-60') ?>

            <span class="font-bold text-[#646464]">No assessments yet</span>
            <span class="text-sm text-[#767676]">Quizzes and assignments for this course will show up here with your scores.</span>

        </div>

    <?php else: ?>

        <div class="flex flex-col gap-6">

            <?php foreach ($groups as $type => $items): ?>

                <div class="flex flex-col gap-3">

                    <span class="uppercase font-bold text-xs tracking-wide text-[#767676]">
                        <?= esc($typeHeadings[$type] ?? $type) ?>
                    </span>

                    <div class="flex flex-col gap-3">

                        <?php foreach ($items as $assessment): ?>

                            <?php
                            $state = $assessment['state'];
                            $score = $assessment['score'];
                            $totalPoints = $assessment['total_points'];
                            $scoreText = $score !== null ? $formatPoints((float) $score) . '/' . $formatPoints($totalPoints) : null;
                            ?>

                            <div
                                class="group px-8 py-6 flex justify-between items-center gap-6 rounded-2xl border-[1px] border-[#A9A9A9]/50
                                        transition-all duration-200
                                        hover:border-brand/50
                                        hover:bg-brand/5
                                        hover:shadow-sm
                                        cursor-pointer"
                                data-assessment="<?= esc($assessment['id']) ?>"
                                data-grade-state="<?= esc($state) ?>">

                                <div class="flex min-w-0 items-center gap-5">

                                    <div class="h-10 w-10 shrink-0 flex items-center justify-center border-3 border-brand rounded-[100%] transition-transform duration-200 group-hover:scale-105">
                                        <?= learning_item_icon($type, 'h-5 w-5 text-brand') ?>
                                    </div>

                                    <div class="flex min-w-0 flex-col">

                                        <span class="truncate font-bold text-lg text-[#646464]">
                                            <?= esc($assessment['title']) ?>
                                        </span>

                                        <span class="text-sm text-[#767676]">
                                            <?= esc($assessment['due_label']) ?> · <?= esc($formatPoints($totalPoints)) ?> points
                                        </span>

                                    </div>

                                </div>

                                <?php if ($state === 'graded' && $scoreText !== null): ?>

                                    <div class="flex shrink-0 flex-col justify-center items-end gap-1">

                                        <span class="font-bold text-lg text-brand">
                                            <?= esc($scoreText) ?>
                                        </span>

                                        <span class="text-xs text-[#767676]">
                                            <?= $totalPoints > 0 ? esc(grade_percent($score / $totalPoints * 100)) : '' ?>
                                        </span>

                                    </div>

                                <?php elseif ($state === 'partially_graded' && $scoreText !== null): ?>

                                    <!-- Auto-graded part; essays / uploads still being graded -->
                                    <div class="flex shrink-0 flex-col justify-center items-end gap-1">

                                        <span class="rounded-full px-3 py-1 text-sm font-bold bg-[#FFF1D6] text-[#946200]">
                                            <?= esc($scoreText) ?> so far
                                        </span>

                                        <span class="text-xs text-[#767676]">The rest is being graded</span>

                                    </div>

                                <?php elseif ($state === 'resubmitted' && $scoreText !== null): ?>

                                    <!-- The previous grade stands until the resubmission is graded. -->
                                    <div class="flex shrink-0 flex-col justify-center items-end gap-1">

                                        <span class="font-bold text-lg text-brand">
                                            <?= esc($scoreText) ?>
                                        </span>

                                        <span class="rounded-full px-2 py-0.5 text-[10px] font-bold bg-brand/10 text-brand">
                                            Resubmitted · being reviewed
                                        </span>

                                    </div>

                                <?php else: ?>

                                    <span class="shrink-0 rounded-full px-3 py-1 text-xs font-black <?= $chipClasses[$assessment['tone']] ?? $chipClasses['neutral'] ?>">
                                        <?= esc(match ($state) {
                                            'grading', 'submitted' => 'Waiting for grade',
                                            'missed' => 'Missing',
                                            'overdue' => 'Overdue · still accepted',
                                            default => $assessment['label'],
                                        }) ?>
                                    </span>

                                <?php endif; ?>

                            </div>

                        <?php endforeach; ?>

                    </div>

                </div>

            <?php endforeach; ?>

        </div>

    <?php endif; ?>

</div>
