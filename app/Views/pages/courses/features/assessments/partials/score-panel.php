<?php

$userRole = esc($user->getGroups()[0] ?? '');

$isInstructor = $userRole === 'instructor';

$totalPoints = (float) ($currentAssessment['total_points'] ?? 0);

$submission ??= null;

/*
|--------------------------------------------------------------------------
| Instructor viewing without a selected student (e.g. view.php via the
| eye button) has no student context to show a score for — skip the panel.
|--------------------------------------------------------------------------
*/

$hasStudentContext = $isInstructor
    ? !empty($selectedStudent)
    : true;

$scoreHeading = $isInstructor
    ? esc($selectedStudent['student_name'] ?? 'Student') . "'s Score"
    : 'Your Score';

$feedbackHeading = $isInstructor
    ? esc($selectedStudent['student_name'] ?? 'Student') . "'s Feedback"
    : 'Feedback';

/*
|--------------------------------------------------------------------------
| The score shown is the attempt that counts (AssessmentResultService):
| the best quiz attempt, or the latest graded assignment submission. When
| $scoreResult isn't given, fall back to $submission as before.
|--------------------------------------------------------------------------
*/

$scoreResult ??= null;

$scored = $scoreResult !== null ? $scoreResult['counted'] : $submission;

$isGraded = !empty($scored['is_graded']);

$scoreCaption = null;

if ($isGraded && $scoreResult !== null) {

    $scoreCaption = $scoreResult['policy'] === 'highest'
        ? (count($scoreResult['finished']) > 1 ? 'Highest score · Attempt ' . (int) $scored['attempt_number'] : null)
        : ($scoreResult['resubmitted']
            ? ($isInstructor ? 'Previous submission — a resubmission is waiting' : 'For your previous submission — your resubmission is being reviewed')
            : null);

}
?>

<?php if ($hasStudentContext): ?>

    <div class="w-1/5 flex flex-col gap-8">

        <!-- Score -->
        <div class="flex flex-col gap-3">

            <div class="flex items-center gap-3">
                <?= icon('score', 'h-5 w-5') ?>

                <span class="font-bold text-lg text-[#0040A6]">
                    <?= $scoreHeading ?>
                </span>
            </div>

            <?php if ($isGraded): ?>

                <div class="w-full aspect-square p-10 flex flex-col justify-center items-center bg-[#F9F9FC] border-1 border-[#DBDBDB] rounded-2xl gap-2">

                    <span class="font-bold text-4xl text-[#0040A6]">
                        <?= esc((int) $scored['score']) ?>/<?= esc((int) $totalPoints) ?>
                    </span>

                    <span class="text-sm font-semibold text-[#939393]">
                        <?= esc((float) ($scored['percentage'] ?? 0)) ?>%
                    </span>

                    <?php if ($scoreCaption): ?>
                        <span class="text-center text-xs text-[#939393]"><?= esc($scoreCaption) ?></span>
                    <?php endif; ?>

                </div>

            <?php elseif (!empty($scoreResult['partial'])): ?>

                <!-- Auto-graded part of a quiz; essays / uploads still being graded -->
                <div class="w-full aspect-square p-10 flex flex-col justify-center items-center bg-[#FFF8E6] border-1 border-[#FFE3A3] rounded-2xl gap-2">

                    <span class="font-bold text-4xl text-[#946200]">
                        <?= esc((int) $scoreResult['partial']['score']) ?>/<?= esc((int) $totalPoints) ?>
                    </span>

                    <span class="text-sm font-semibold text-[#946200]">
                        So far
                    </span>

                    <span class="text-xs text-center text-[#946200]">
                        <?= $isInstructor
                            ? 'Auto-graded part — the rest still needs grading.'
                            : 'The rest is still being graded.' ?>
                    </span>

                </div>

            <?php else: ?>

                <div class="w-full aspect-square p-10 flex flex-col justify-center items-center bg-[#F9F9FC] border-1 border-[#DBDBDB] rounded-2xl gap-2">

                    <?= icon('no-score', 'h-10 w-10') ?>

                    <span class="font-bold text-md text-[#939393]">
                        No Score Yet
                    </span>

                    <span class="text-xs text-[#939393] text-center">
                        <?= $isInstructor
                            ? 'A score will appear here once this student has been graded.'
                            : 'Your score will appear here once the instructor has given the grade.' ?>
                    </span>

                </div>

            <?php endif; ?>

        </div>

        <!-- Feedbacks -->
        <div class="flex flex-col gap-3">

            <div class="flex items-center gap-3">
                <?= icon('feedbacks', 'h-5 w-5') ?>

                <span class="font-bold text-lg text-[#0040A6]">
                    <?= $feedbackHeading ?>
                </span>
            </div>

            <?php if ($isGraded && !empty($scored['feedback'])): ?>

                <div class="w-full aspect-square p-10 flex flex-col justify-center items-center bg-[#F9F9FC] border-1 border-[#DBDBDB] rounded-2xl gap-2">

                    <p class="text-sm text-[#5B5B5B] text-center">
                        <?= nl2br(esc($scored['feedback'])) ?>
                    </p>

                </div>

            <?php else: ?>

                <div class="w-full aspect-square p-10 flex flex-col justify-center items-center bg-[#F9F9FC] border-1 border-[#DBDBDB] rounded-2xl gap-2">

                    <?= icon('no-feedback', 'h-10 w-10') ?>

                    <span class="font-bold text-md text-[#939393]">
                        No Feedback Yet
                    </span>

                    <span class="text-xs text-[#939393] text-center">
                        <?= $isInstructor
                            ? 'Feedback you leave for this student will appear here.'
                            : 'Feedback will appear here once your work is graded.' ?>
                    </span>

                </div>

            <?php endif; ?>

        </div>

    </div>

<?php endif; ?>
