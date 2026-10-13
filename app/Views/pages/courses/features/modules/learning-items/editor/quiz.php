<?php

$quizCounts = $assessmentContext['counts'] ?? null;

$questionCount = (int) ($assessmentContext['questionCount'] ?? 0);
?>

<?php if (!$currentAssessment): ?>

    <div class="flex flex-col items-center justify-center gap-2 rounded-2xl border border-dashed border-[#DBDBDB] bg-[#F9F9FC] p-10 text-center">
        <span class="text-sm font-semibold text-[#646464]">No quiz is linked to this learning item</span>
        <span class="text-xs text-[#767676]">Delete this item and add the quiz again from the learning unit.</span>
    </div>

<?php else: ?>

    <div class="flex flex-col gap-6 rounded-2xl border border-gray-200 bg-white p-6 shadow-sm">

        <?= $this->include('pages/courses/features/modules/partials/assessment-summary') ?>

        <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">

            <?php foreach ([
                ['label' => 'Questions', 'value' => $questionCount, 'class' => 'text-brand'],
                ['label' => 'Submitted', 'value' => (int) ($quizCounts['submitted'] ?? 0), 'class' => 'text-brand'],
                ['label' => 'To Grade', 'value' => (int) ($quizCounts['toGrade'] ?? 0), 'class' => 'text-[#946200]'],
                ['label' => 'Graded', 'value' => (int) ($quizCounts['graded'] ?? 0), 'class' => 'text-emerald-700'],
            ] as $stat): ?>

                <div class="flex flex-col gap-1 rounded-xl bg-[#F9F9FC] p-4">
                    <span class="text-2xl font-bold <?= $stat['class'] ?>"><?= esc($stat['value']) ?></span>
                    <span class="text-xs font-semibold uppercase tracking-wide text-[#767676]"><?= esc($stat['label']) ?></span>
                </div>

            <?php endforeach; ?>

        </div>

        <?php if ($questionCount === 0): ?>

            <div class="rounded-xl border border-[#FFE3A3] bg-[#FFF8E6] px-4 py-3 text-sm text-[#946200]">
                This quiz has no questions yet. Add some in the Quiz Builder before you publish it.
            </div>

        <?php endif; ?>

        <div class="flex flex-col gap-2">

            <span class="text-xs font-bold uppercase tracking-wide text-[#767676]">Instructions</span>

            <?= $this->setData(['instructionsEmptyText' => 'No instructions yet. Add them in Edit Settings.'])
                ->include('pages/courses/features/assessments/partials/instructions-paragraph') ?>

        </div>

        <div class="flex flex-wrap items-center gap-3 border-t border-gray-100 pt-5">

            <button
                type="button"
                data-open-assessment="<?= esc($currentAssessment['id']) ?>"
                data-assessment-mode="builder"
                class="rounded-xl bg-brand px-5 py-2.5 text-sm font-semibold text-white transition-colors hover:bg-brand-dark">
                Open Quiz Builder
            </button>

            <button
                type="button"
                data-open-assessment="<?= esc($currentAssessment['id']) ?>"
                data-assessment-mode="editor"
                class="rounded-xl border border-gray-300 bg-white px-5 py-2.5 text-sm font-semibold text-gray-700 transition-colors hover:bg-gray-50">
                Edit Settings
            </button>

            <button
                type="button"
                data-open-assessment="<?= esc($currentAssessment['id']) ?>"
                data-assessment-mode="grade"
                class="rounded-xl border border-gray-300 bg-white px-5 py-2.5 text-sm font-semibold text-gray-700 transition-colors hover:bg-gray-50">
                View Submissions
            </button>

            <span class="ml-auto text-xs text-[#767676]">
                Publishing or drafting this item also publishes or drafts the quiz.
            </span>

        </div>

    </div>

<?php endif; ?>
