<?php

$isInstructor = $user->inGroup('instructor');

$assignmentCounts = $assessmentContext['counts'] ?? null;
?>

<?php if (!$currentAssessment): ?>

    <div class="flex flex-col items-center justify-center gap-2 rounded-2xl border border-dashed border-[#DBDBDB] bg-[#F9F9FC] p-10 text-center">
        <span class="text-sm font-semibold text-[#646464]">This assignment isn't set up yet</span>
        <span class="text-xs text-[#767676]">No assignment is linked to this learning item.</span>
    </div>

<?php else: ?>

    <div class="flex flex-col gap-8">

        <div class="flex flex-wrap items-center justify-between gap-3">

            <?= $this->include('pages/courses/features/modules/partials/assessment-summary') ?>

            <?php if ($isInstructor): ?>

                <div class="flex items-center gap-3">

                    <span class="text-xs text-[#646464]">
                        <strong class="text-brand"><?= (int) ($assignmentCounts['submitted'] ?? 0) ?></strong> submitted
                        · <strong class="text-[#946200]"><?= (int) ($assignmentCounts['toGrade'] ?? 0) ?></strong> to grade
                    </span>

                    <button
                        type="button"
                        data-open-assessment="<?= esc($currentAssessment['id']) ?>"
                        data-assessment-mode="grade"
                        class="rounded-xl border border-gray-300 bg-white px-4 py-2 text-sm font-semibold text-gray-700 transition-colors hover:bg-gray-50">
                        View Submissions
                    </button>

                </div>

            <?php endif; ?>

        </div>

        <?= $this->include('pages/courses/features/assessments/assessment-types/views/assignment') ?>

    </div>

<?php endif; ?>
