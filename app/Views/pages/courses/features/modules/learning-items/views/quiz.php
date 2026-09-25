<?php

$isInstructor = $user->inGroup('instructor');

$quizState = $assessmentContext['state'] ?? null;

$quizCounts = $assessmentContext['counts'] ?? null;

$questionCount = (int) ($assessmentContext['questionCount'] ?? 0);

$latestAttempt = $quizState['latest'] ?? null;

$totalPoints = (int) ($currentAssessment['total_points'] ?? 0);
?>

<?php if (!$currentAssessment): ?>

    <div class="flex flex-col items-center justify-center gap-2 rounded-2xl border border-dashed border-[#DBDBDB] bg-[#F9F9FC] p-10 text-center">
        <span class="text-sm font-semibold text-[#5B5B5B]">This quiz isn't set up yet</span>
        <span class="text-xs text-[#939393]">No quiz is linked to this learning item.</span>
    </div>

<?php else: ?>

    <div class="flex flex-col gap-8">

        <?= $this->include('pages/courses/features/modules/partials/assessment-summary') ?>

        <!-- Instructions -->
        <div class="flex flex-col gap-3">

            <div class="flex items-center gap-3">
                <?= icon('info', 'h-5 w-5') ?>

                <span class="font-bold text-lg text-[#0040A6]">
                    Instructions
                </span>
            </div>

            <?= $this->setData(['instructionsEmptyText' => 'No instructions were provided for this quiz.'])
                ->include('pages/courses/features/assessments/partials/instructions-paragraph') ?>

        </div>

        <!-- Quiz -->
        <div class="flex w-full flex-col items-center justify-center gap-5 rounded-2xl border border-[#DBDBDB] bg-linear-to-b from-[#F4F8FF] to-white px-10 py-12 text-center">

            <div class="flex h-16 w-16 items-center justify-center rounded-full bg-[#0040A6]/10">
                <?= icon('quiz', 'h-8 w-8') ?>
            </div>

            <?php if ($isInstructor): ?>

                <div class="flex flex-col gap-1.5">
                    <span class="text-lg font-bold text-[#1F2937]">
                        <?= $questionCount ?> question<?= $questionCount === 1 ? '' : 's' ?>
                    </span>
                    <span class="text-sm text-[#6B7280]">
                        <?= $questionCount === 0
                            ? "Students can't answer anything until you add questions."
                            : 'Students take this quiz from here or from the Assessments tab.' ?>
                    </span>
                </div>

                <div class="flex items-center gap-6 text-sm text-[#5B5B5B]">
                    <span><strong class="text-[#0040A6]"><?= (int) ($quizCounts['submitted'] ?? 0) ?></strong> submitted</span>
                    <span><strong class="text-[#946200]"><?= (int) ($quizCounts['toGrade'] ?? 0) ?></strong> to grade</span>
                    <span><strong class="text-emerald-700"><?= (int) ($quizCounts['graded'] ?? 0) ?></strong> graded</span>
                </div>

                <div class="flex flex-wrap items-center justify-center gap-3">

                    <button
                        type="button"
                        data-open-assessment="<?= esc($currentAssessment['id']) ?>"
                        data-assessment-mode="builder"
                        class="rounded-xl bg-[#0040A6] px-6 py-2.5 text-sm font-semibold text-white shadow-sm transition-colors hover:bg-[#002068]">
                        Open Quiz Builder
                    </button>

                    <button
                        type="button"
                        data-open-assessment="<?= esc($currentAssessment['id']) ?>"
                        data-assessment-mode="grade"
                        class="rounded-xl border border-gray-300 bg-white px-6 py-2.5 text-sm font-semibold text-gray-700 transition-colors hover:bg-gray-50">
                        View Submissions
                    </button>

                </div>

            <?php else: ?>

                <div class="flex flex-col gap-1.5">

                    <span class="text-lg font-bold text-[#1F2937]">
                        <?php if (!empty($quizState['inProgress'])): ?>
                            You have a quiz in progress
                        <?php elseif ($latestAttempt): ?>
                            <?= match (true) {
                                !empty($quizState['best']) => 'Best score: ' . (int) $quizState['best']['score'] . '/' . $totalPoints,
                                !empty($quizState['result']['partial']) => (int) $quizState['result']['partial']['score'] . '/' . $totalPoints . ' so far — the rest is being graded',
                                default => 'Submitted — waiting for grading',
                            } ?>
                        <?php else: ?>
                            Ready to test your knowledge?
                        <?php endif; ?>
                    </span>

                    <span class="text-sm text-[#6B7280]">
                        <?= esc((int) ($quizState['attemptsUsed'] ?? 0)) ?> of <?= esc((int) ($quizState['attemptLimit'] ?? 1)) ?> attempt<?= (int) ($quizState['attemptLimit'] ?? 1) === 1 ? '' : 's' ?> used
                        <?php if (!empty($currentAssessment['duration'])): ?>
                            · Once you start, the <?= (int) $currentAssessment['duration'] ?>-minute timer keeps running.
                        <?php endif; ?>
                    </span>

                </div>

                <?php if (!empty($quizState['canTake'])): ?>

                    <button
                        type="button"
                        data-open-assessment="<?= esc($currentAssessment['id']) ?>"
                        data-assessment-mode="take"
                        class="mt-2 inline-flex items-center gap-2 rounded-xl bg-[#0040A6] px-8 py-3 text-sm font-semibold text-white shadow-sm transition-colors hover:bg-[#002068]">

                        <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="currentColor" viewBox="0 0 24 24">
                            <path d="M8 5v14l11-7z" />
                        </svg>

                        <?= !empty($quizState['inProgress']) ? 'Resume Quiz' : ($latestAttempt ? 'Retake Quiz' : 'Take Quiz') ?>

                    </button>

                <?php else: ?>

                    <span class="rounded-xl bg-gray-100 px-5 py-2.5 text-sm font-semibold text-gray-500">
                        <?= esc($quizState['reason'] ?? "This quiz isn't available.") ?>
                    </span>

                <?php endif; ?>

            <?php endif; ?>

        </div>

        <?php if (!$isInstructor): ?>

            <?= view('pages/courses/features/assessments/partials/attempt-history', [
                'historyAttempts' => $quizState['attempts'] ?? [],
                'historyType' => 'quiz',
                'totalPoints' => $totalPoints,
                'countedAttemptId' => $quizState['counted']['id'] ?? null,
            ]) ?>

        <?php endif; ?>

    </div>

<?php endif; ?>
