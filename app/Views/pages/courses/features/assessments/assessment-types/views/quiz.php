<?php $userRole = esc($user->getGroups()[0] ?? ''); ?>

<div class="flex flex-col gap-8">

    <!-- Your Work -->
    <div class="flex flex-col gap-3">

        <div class="flex items-center gap-3">
            <img
                class="w-5 h-5"
                src="<?= base_url('assets/images/icons/work.png') ?>" alt="Instructions Icon">

            <span class="font-bold text-lg text-[#0040A6]">
                Your Work
            </span>
        </div>

        <div class="flex w-full min-h-100 flex-col items-center justify-center gap-5 rounded-2xl border border-[#DBDBDB] bg-linear-to-b from-[#F4F8FF] to-white px-10 py-12 text-center">

            <div class="flex h-16 w-16 items-center justify-center rounded-full bg-[#0040A6]/10">
                <img
                    class="h-8 w-8"
                    src="<?= base_url('assets/images/icons/quiz.png') ?>" alt="">
            </div>

            <div class="flex flex-col gap-1.5">

                <span class="text-lg font-bold text-[#1F2937]">
                    <?= $userRole === 'instructor' ? 'Students will take this quiz here' : 'Ready to test your knowledge?' ?>
                </span>

                <span class="text-sm text-[#6B7280]">
                    <?= $userRole === 'instructor'
                        ? 'This is a preview of what students see before starting the quiz.'
                        : 'Once you start, make sure you have enough time to finish in one sitting.' ?>
                </span>

            </div>

            <div class="flex items-center gap-6 text-sm text-[#5B5B5B]">

                <?php if (!empty($currentAssessment['duration'])): ?>
                    <div class="flex items-center gap-1.5">
                        <img class="h-4 w-4 opacity-60" src="<?= base_url('assets/images/icons/time.png') ?>" alt="">
                        <span><?= esc($currentAssessment['duration']) ?> min</span>
                    </div>
                <?php endif; ?>

                <div class="flex items-center gap-1.5">
                    <img class="h-4 w-4 opacity-60" src="<?= base_url('assets/images/icons/work.png') ?>" alt="">
                    <span><?= esc((int) ($currentAssessment['total_points'] ?? 0)) ?> points</span>
                </div>

                <div class="flex items-center gap-1.5">
                    <img class="h-4 w-4 opacity-60" src="<?= base_url('assets/images/icons/start.png') ?>" alt="">
                    <span><?= esc((int) ($currentAssessment['attempt_limit'] ?? 1)) ?> attempt<?= (int) ($currentAssessment['attempt_limit'] ?? 1) === 1 ? '' : 's' ?></span>
                </div>

            </div>

            <?php if ($userRole !== 'instructor'): ?>

                <button
                    type="button"
                    data-take-quiz
                    class="mt-2 inline-flex items-center gap-2 rounded-xl bg-[#0040A6] px-8 py-3 text-sm font-semibold text-white shadow-sm transition-colors hover:bg-[#002068]">

                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="currentColor" viewBox="0 0 24 24">
                        <path d="M8 5v14l11-7z" />
                    </svg>

                    Take Quiz

                </button>

            <?php endif; ?>

        </div>

    </div>

    <!-- Submission History -->
    <div class="flex flex-col gap-3">

        <div class="flex items-center gap-3">
            <span class="font-bold text-md text-[#0040A6]">
                Submission History
            </span>
        </div>

        <div class="flex gap-2">
  
            <span class="text-sm italic text-[#5B5B5B]">
                No previous drafts uploaded for this assignment.
            </span>

        </div>
        
    </div>

</div>