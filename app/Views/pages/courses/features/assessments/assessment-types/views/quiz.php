<?php $userRole = \App\Services\Courses\CourseViewer::seesInstructorView($user) ? 'instructor' : esc($user->getGroups()[0] ?? ''); ?>

<div class="flex flex-col gap-8">

    <!-- Your Work -->
    <div class="flex flex-col gap-3">

        <div class="flex items-center gap-3">
            <?= icon('work', 'h-5 w-5') ?>

            <span class="font-bold text-lg text-brand">
                Your Work
            </span>
        </div>

        <div class="flex w-full min-h-100 flex-col items-center justify-center gap-5 rounded-2xl border border-[#DBDBDB] bg-linear-to-b from-[#F4F8FF] to-white px-10 py-12 text-center">

            <div class="flex h-16 w-16 items-center justify-center rounded-full bg-brand/10">
                <?= icon('quiz', 'h-8 w-8') ?>
            </div>

            <div class="flex flex-col gap-1.5">

                <span class="text-lg font-bold text-[#1A1C1E]">
                    <?= $userRole === 'instructor' ? 'Students will take this quiz here' : 'Ready to test your knowledge?' ?>
                </span>

                <span class="text-sm text-[#646464]">
                    <?= $userRole === 'instructor'
                        ? 'This is a preview of what students see before starting the quiz.'
                        : 'Once you start, make sure you have enough time to finish in one sitting.' ?>
                </span>

            </div>

            <div class="flex items-center gap-6 text-sm text-[#646464]">

                <?php if (!empty($currentAssessment['duration'])): ?>
                    <div class="flex items-center gap-1.5">
                        <?= icon('time', 'h-4 w-4 opacity-60') ?>
                        <span><?= esc($currentAssessment['duration']) ?> min</span>
                    </div>
                <?php endif; ?>

                <div class="flex items-center gap-1.5">
                    <?= icon('work', 'h-4 w-4 opacity-60') ?>
                    <span><?= esc((int) ($currentAssessment['total_points'] ?? 0)) ?> points</span>
                </div>

                <div class="flex items-center gap-1.5">
                    <?= icon('start', 'h-4 w-4 opacity-60') ?>
                    <span><?= esc((int) ($currentAssessment['attempt_limit'] ?? 1)) ?> attempt<?= (int) ($currentAssessment['attempt_limit'] ?? 1) === 1 ? '' : 's' ?></span>
                </div>

            </div>

            <?php if ($userRole !== 'instructor' && ($quizState['canTake'] ?? true)): ?>

                <button
                    type="button"
                    data-take-quiz
                    class="mt-2 inline-flex items-center gap-2 rounded-xl bg-brand px-8 py-3 text-sm font-semibold text-white shadow-sm transition-colors hover:bg-brand-dark">

                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="currentColor" viewBox="0 0 24 24">
                        <path d="M8 5v14l11-7z" />
                    </svg>

                    <?= !empty($quizState['inProgress']) ? 'Resume Quiz' : (!empty($quizState['latest']) ? 'Retake Quiz' : 'Take Quiz') ?>

                </button>

            <?php elseif ($userRole !== 'instructor'): ?>

                <span class="mt-2 rounded-xl bg-gray-100 px-5 py-2.5 text-sm font-semibold text-gray-500">
                    <?= esc($quizState['reason'] ?? "This quiz isn't available.") ?>
                </span>

            <?php endif; ?>

        </div>

    </div>

    <?php if ($userRole !== 'instructor'): ?>

        <!-- Attempt History -->
        <?= view('pages/courses/features/assessments/partials/attempt-history', [
            'historyAttempts' => $quizState['attempts'] ?? [],
            'historyType' => 'quiz',
            'totalPoints' => (int) ($currentAssessment['total_points'] ?? 0),
            'countedAttemptId' => $quizState['counted']['id'] ?? null,
        ]) ?>

    <?php endif; ?>


</div>