<?php

$assignmentCounts = $assessmentContext['counts'] ?? null;

$submissionTypes = array_keys(array_filter([
    'File Upload' => !empty($currentSettings['allow_file_upload']),
    'Text Entry' => !empty($currentSettings['allow_text_entry']),
    'Link' => !empty($currentSettings['allow_link_entry']),
]));
?>

<?php if (!$currentAssessment): ?>

    <div class="flex flex-col items-center justify-center gap-2 rounded-2xl border border-dashed border-[#DBDBDB] bg-[#F9F9FC] p-10 text-center">
        <span class="text-sm font-semibold text-[#646464]">No assignment is linked to this learning item</span>
        <span class="text-xs text-[#767676]">Delete this item and add the assignment again from the learning unit.</span>
    </div>

<?php else: ?>

    <div class="flex flex-col gap-6 rounded-2xl border border-gray-200 bg-white p-6 shadow-sm">

        <?= $this->include('pages/courses/features/modules/partials/assessment-summary') ?>

        <div class="grid grid-cols-1 gap-3 md:grid-cols-3">

            <div class="flex flex-col gap-2 rounded-xl bg-[#F9F9FC] p-4">

                <span class="text-xs font-bold uppercase tracking-wide text-gray-400">Students Submit By</span>

                <?php if ($submissionTypes): ?>
                    <div class="flex flex-wrap gap-1.5">
                        <?php foreach ($submissionTypes as $type): ?>
                            <span class="rounded-full bg-white px-2.5 py-0.5 text-xs font-semibold text-[#0040A6] ring-1 ring-[#0040A6]/20"><?= esc($type) ?></span>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <span class="text-sm font-semibold text-[#946200]">Nothing turned on yet</span>
                <?php endif; ?>

            </div>

            <div class="flex flex-col gap-2 rounded-xl bg-[#F9F9FC] p-4">

                <span class="text-xs font-bold uppercase tracking-wide text-gray-400">Rubric</span>

                <span class="text-sm font-semibold text-[#646464]">
                    <?= $currentRubric
                        ? esc($currentRubric['title']) . ' · ' . count($currentRubric['criteria'] ?? []) . ' criteria'
                        : 'No rubric' ?>
                </span>

            </div>

            <div class="flex flex-col gap-2 rounded-xl bg-[#F9F9FC] p-4">

                <span class="text-xs font-bold uppercase tracking-wide text-gray-400">Submissions</span>

                <span class="text-sm text-[#646464]">
                    <strong class="text-[#0040A6]"><?= (int) ($assignmentCounts['submitted'] ?? 0) ?></strong> submitted
                    · <strong class="text-[#946200]"><?= (int) ($assignmentCounts['toGrade'] ?? 0) ?></strong> to grade
                    · <strong class="text-emerald-700"><?= (int) ($assignmentCounts['graded'] ?? 0) ?></strong> graded
                </span>

            </div>

        </div>

        <?php if (!$submissionTypes): ?>

            <div class="rounded-xl border border-[#FFE3A3] bg-[#FFF8E6] px-4 py-3 text-sm text-[#946200]">
                Students can't submit until you allow file upload, text entry, or a link in Edit Instructions & Submission.
            </div>

        <?php endif; ?>

        <div class="flex flex-col gap-2">

            <span class="text-xs font-bold uppercase tracking-wide text-gray-400">Instructions</span>

            <?= $this->setData(['instructionsEmptyText' => 'No instructions yet. Add them in Edit Instructions & Submission.'])
                ->include('pages/courses/features/assessments/partials/instructions-paragraph') ?>

        </div>

        <div class="flex flex-wrap items-center gap-3 border-t border-gray-100 pt-5">

            <button
                type="button"
                data-open-assessment="<?= esc($currentAssessment['id']) ?>"
                data-assessment-mode="editor"
                class="rounded-xl bg-[#0040A6] px-5 py-2.5 text-sm font-semibold text-white transition-colors hover:bg-[#002068]">
                Edit Instructions & Submission
            </button>

            <button
                type="button"
                data-open-assessment="<?= esc($currentAssessment['id']) ?>"
                data-assessment-mode="grade"
                class="rounded-xl border border-gray-300 bg-white px-5 py-2.5 text-sm font-semibold text-gray-700 transition-colors hover:bg-gray-50">
                View Submissions
            </button>

            <span class="ml-auto text-xs text-gray-400">
                Publishing or drafting this item also publishes or drafts the assignment.
            </span>

        </div>

    </div>

<?php endif; ?>
