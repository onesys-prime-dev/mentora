<?php
/**
 * Grouped by assessment type (Quizzes, Assignments, Exams...) rather than
 * one flat list — a student scanning their grades thinks in those buckets,
 * and it's the same grouping the gradebook table uses for its columns, so
 * the two views describe the same structure two ways.
 */
$groups = [];

foreach (($assessments ?? []) as $assessment) {
    $type = $assessment['assessment_type'] ?? 'Other';
    $groups[$type][] = $assessment;
}
?>

<div class="flex flex-col gap-3 w-full">

    <div class="flex items-center gap-4">

        <img src="<?= base_url('assets/images/icons/assessments.png') ?>" alt="">

        <span class="font-bold text-2xl text-[#0040A6]">
            Grades
        </span>

    </div>

    <?php if ($groups === []): ?>

        <div class="px-8 py-10 flex flex-col items-center justify-center gap-2 text-center rounded-2xl border-[1px] border-[#A9A9A9]/50">

            <img class="h-8 w-8 opacity-60" src="<?= base_url('assets/images/icons/no-score.png') ?>" alt="">

            <span class="font-bold text-[#646464]">Nothing graded yet</span>
            <span class="text-sm text-[#878787]">Scores for this course will show up here as they're released.</span>

        </div>

    <?php else: ?>

        <div class="flex flex-col gap-6">

            <?php foreach ($groups as $type => $items): ?>

                <div class="flex flex-col gap-3">

                    <span class="uppercase font-bold text-xs tracking-wide text-[#878787]">
                        <?= esc($type) ?>
                    </span>

                    <div class="flex flex-col gap-3">

                        <?php foreach ($items as $assessment): ?>

                            <?php
                            $isGraded = ($assessment['submission_status'] ?? null) === 'Graded';
                            $score = $assessment['submission_score'] ?? null;
                            $totalPoints = (int) ($assessment['total_points'] ?? 0);
                            ?>

                            <div
                                class="px-8 py-6 flex justify-between items-center rounded-2xl border-[1px] border-[#A9A9A9]/50
                                        transition-all duration-200
                                        hover:border-[#0040A6]/50
                                        hover:bg-[#0040A6]/5
                                        hover:shadow-sm
                                        cursor-pointer"
                                data-assessment="<?= esc($assessment['id']) ?>">

                                <div class="flex items-center gap-5">

                                    <div class="h-10 w-10 flex items-center justify-center border-3 border-[#0040A6] rounded-[100%]">
                                        <img class="h-5 w-5" src="<?= base_url('assets/images/icons/quiz.png') ?>" alt="">
                                    </div>

                                    <div class="flex flex-col">

                                        <span class="font-bold text-lg text-[#646464]">
                                            <?= esc($assessment['title']) ?>
                                        </span>

                                        <span class="text-sm text-[#878787]">
                                            Due: <?= esc(date('F j, Y', strtotime($assessment['due_date']))) ?> | <?= esc($totalPoints) ?> Points
                                        </span>

                                    </div>

                                </div>

                                <?php if ($isGraded): ?>

                                    <div class="flex flex-col justify-center items-end gap-1">

                                        <span class="font-bold text-lg text-[#0040A6]">
                                            <?= esc((int) $score) ?>/<?= esc($totalPoints) ?>
                                        </span>

                                        <span class="text-xs text-[#878787]">
                                            <?= $totalPoints > 0 ? esc(round(($score / $totalPoints) * 100)) . '%' : '' ?>
                                        </span>

                                    </div>

                                <?php else: ?>

                                    <span class="rounded-full px-3 py-1 text-xs font-black bg-gray-100 text-gray-500">
                                        <?= esc($assessment['submission_status'] ?? 'Not Submitted') ?>
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
