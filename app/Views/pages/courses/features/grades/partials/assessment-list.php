<?php
/**
 * The instructor's entry point into grading: assessments grouped by type,
 * each one a clickable item that opens Grade Work for it — click-through
 * to manage answers and assign scores, not a spreadsheet of scores that
 * already exist. Grouping mirrors the student-facing assessment-breakdown
 * partial, so the two views describe the same structure two ways.
 *
 * data-assessment is read by resources/js/pages/courses/features/grades/
 * index.js, which reuses the assessments feature's own loadGradeWork() —
 * the same Grade Work screen already reachable from the Assessments tab.
 */
$typeHeadings = [
    'Quiz'       => 'Quizzes',
    'Assignment' => 'Assignments',
];
?>

<div class="flex flex-col gap-3 w-full">

    <h3 class="text-xl font-bold text-[#0040A6]">Assessments</h3>

    <?php if (($assessmentGroups ?? []) === []): ?>

        <div class="px-8 py-10 flex flex-col items-center justify-center gap-2 text-center rounded-2xl border-[1px] border-[#A9A9A9]/50">

            <?= icon('no-score', 'h-8 w-8 opacity-60') ?>

            <span class="font-bold text-[#646464]">No assessments yet</span>
            <span class="text-sm text-[#767676]">Once you publish an assessment, it'll show up here to grade.</span>

        </div>

    <?php else: ?>

        <div class="flex flex-col gap-6">

            <?php foreach ($assessmentGroups as $type => $items): ?>

                <div class="flex flex-col gap-3">

                    <span class="uppercase font-bold text-xs tracking-wide text-[#767676]">
                        <?= esc($typeHeadings[$type] ?? $type) ?>
                    </span>

                    <div class="flex flex-col gap-3">

                        <?php foreach ($items as $assessment): ?>

                            <?php
                            $needsReview = (int) $assessment['needs_review_count'];
                            $graded = (int) $assessment['graded_count'];

                            $statusMeta = match (true) {
                                $needsReview > 0 => [
                                    'chip'  => 'bg-[#FFF1D6] text-[#946200]',
                                    'label' => $needsReview . ' Need' . ($needsReview === 1 ? 's' : '') . ' Review',
                                ],
                                $graded > 0 => [
                                    'chip'  => 'bg-[#E8F5EC] text-[#2E7D4F]',
                                    'label' => 'All Graded',
                                ],
                                default => [
                                    'chip'  => 'bg-gray-100 text-gray-500',
                                    'label' => 'No Submissions',
                                ],
                            };
                            ?>

                            <div
                                class="group px-8 py-6 flex justify-between items-center rounded-2xl border-[1px] border-[#A9A9A9]/50
                                        transition-all duration-200
                                        hover:border-[#0040A6]/50
                                        hover:bg-[#0040A6]/5
                                        hover:shadow-sm
                                        cursor-pointer"
                                data-assessment="<?= esc($assessment['id']) ?>">

                                <div class="flex items-center gap-5">

                                    <div class="h-10 w-10 flex items-center justify-center border-3 border-[#0040A6] rounded-[100%] transition-transform duration-200 group-hover:scale-105">
                                        <?= learning_item_icon($type, 'h-5 w-5 text-[#0040A6]') ?>
                                    </div>

                                    <div class="flex flex-col">

                                        <span class="font-bold text-lg text-[#646464]">
                                            <?= esc($assessment['title']) ?>
                                        </span>

                                        <span class="text-sm text-[#767676]">
                                            Due: <?= esc(date('F j, Y', strtotime($assessment['due_date']))) ?> | <?= esc((int) $assessment['total_points']) ?> Points
                                        </span>

                                    </div>

                                </div>

                                <div class="flex items-center gap-4">

                                    <span class="rounded-full px-3 py-1 text-xs font-black <?= $statusMeta['chip'] ?>">
                                        <?= esc($statusMeta['label']) ?>
                                    </span>

                                    <svg
                                        xmlns="http://www.w3.org/2000/svg"
                                        class="h-5 w-5 text-[#A9A9A9] transition-transform duration-200 group-hover:translate-x-1 group-hover:text-[#0040A6]"
                                        fill="none"
                                        viewBox="0 0 24 24"
                                        stroke="currentColor"
                                        stroke-width="2">

                                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7" />

                                    </svg>

                                </div>

                            </div>

                        <?php endforeach; ?>

                    </div>

                </div>

            <?php endforeach; ?>

        </div>

    <?php endif; ?>

</div>
