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

$isGraded = !empty($submission['is_graded']);
?>

<?php if ($hasStudentContext): ?>

    <div class="w-1/5 flex flex-col gap-8">

        <!-- Score -->
        <div class="flex flex-col gap-3">

            <div class="flex items-center gap-3">
                <img
                    class="w-5 h-5"
                    src="<?= base_url('assets/images/icons/score.png') ?>" alt="Instructions Icon">

                <span class="font-bold text-lg text-[#0040A6]">
                    <?= $scoreHeading ?>
                </span>
            </div>

            <?php if ($isGraded): ?>

                <div class="w-full aspect-square p-10 flex flex-col justify-center items-center bg-[#F9F9FC] border-1 border-[#DBDBDB] rounded-2xl gap-2">

                    <span class="font-bold text-4xl text-[#0040A6]">
                        <?= esc((int) $submission['score']) ?>/<?= esc((int) $totalPoints) ?>
                    </span>

                    <span class="text-sm font-semibold text-[#939393]">
                        <?= esc((float) ($submission['percentage'] ?? 0)) ?>%
                    </span>

                </div>

            <?php else: ?>

                <div class="w-full aspect-square p-10 flex flex-col justify-center items-center bg-[#F9F9FC] border-1 border-[#DBDBDB] rounded-2xl gap-2">

                    <img src="<?= base_url('assets/images/icons/no-score.png') ?>" alt="">

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
                <img
                    class="w-5 h-5"
                    src="<?= base_url('assets/images/icons/feedbacks.png') ?>" alt="Instructions Icon">

                <span class="font-bold text-lg text-[#0040A6]">
                    <?= $feedbackHeading ?>
                </span>
            </div>

            <?php if ($isGraded && !empty($submission['feedback'])): ?>

                <div class="w-full aspect-square p-10 flex flex-col justify-center items-center bg-[#F9F9FC] border-1 border-[#DBDBDB] rounded-2xl gap-2">

                    <p class="text-sm text-[#5B5B5B] text-center">
                        <?= nl2br(esc($submission['feedback'])) ?>
                    </p>

                </div>

            <?php else: ?>

                <div class="w-full aspect-square p-10 flex flex-col justify-center items-center bg-[#F9F9FC] border-1 border-[#DBDBDB] rounded-2xl gap-2">

                    <img src="<?= base_url('assets/images/icons/no-feedback.png') ?>" alt="">

                    <span class="font-bold text-md text-[#939393]">
                        No Feedback Yet
                    </span>

                    <span class="text-xs text-[#939393] text-center">
                        <?= $isInstructor
                            ? 'Feedback you leave for this student will appear here.'
                            : 'Feedback will appear here once your assignment is graded.' ?>
                    </span>

                </div>

            <?php endif; ?>

        </div>

    </div>

<?php endif; ?>
