<?php

/*
|--------------------------------------------------------------------------
| Learning item footer.
|
| Students: [Previous] … [Mark as Completed] [Continue]. Continue appears
| once the item is completed (resources/js/pages/courses/features/modules/
| completion/completion.js reveals it after marking). On the module's last
| item it reads "Back to Modules". A locked item shows neither.
|
| When the next item is itself locked (it waits on something other than
| this item), Continue stays visible but disabled, with a lock and a hint
| naming what's missing; completion.js enables it the moment the server
| reports that item unlocked. Previous is disabled the same way if locked.
|
| Instructors: [Previous] … [Continue], as before.
|--------------------------------------------------------------------------
*/

$isStudent = $user->inGroup('student');

$isLocked = !empty($currentLock);

$isCompleted = !empty($currentItem['is_completed']);

$canComplete = !empty($currentItem['can_complete']);

$showContinue = !$isStudent || ($isCompleted && !$isLocked);

$nextLocked = $isStudent && $nextItem && !empty($nextItem['is_locked']);

$previousLocked = $isStudent && $previousItem && !empty($previousItem['is_locked']);

// "Complete “X” to continue." — from ModuleService (lockMessage wording).
$nextLockHint = $nextLocked ? ($nextLockHint ?? '') : '';

$continueClasses = 'inline-flex items-center gap-2 rounded-lg bg-[#0040A6] px-5 py-2.5 text-sm font-semibold text-white transition hover:bg-[#0056E0]';
?>

<footer
    class="flex items-center justify-between py-4 border-t border-white/10"
    data-learning-item-footer>

    <button
        type="button"
        class="learning-item-link inline-flex items-center gap-2 rounded-lg border border-gray-300 bg-gray-100 px-4 py-2.5 text-sm font-medium transition
            <?= $previousItem && !$previousLocked ? 'text-gray-500 hover:border-[#0040A6] hover:bg-[#0040A6]/10 hover:text-[#6EA8FF]' : 'cursor-not-allowed opacity-50' ?>"
        <?= $previousItem && !$previousLocked ? 'data-learning-item="' . esc($previousItem['id']) . '"' : 'disabled' ?>
        <?= $previousLocked ? 'title="Locked"' : '' ?>
    >
        <svg
            class="h-4 w-4"
            xmlns="http://www.w3.org/2000/svg"
            viewBox="0 0 24 24"
            fill="none"
            stroke="currentColor"
            stroke-width="2.5"
            stroke-linecap="round"
            stroke-linejoin="round"
        >
            <path d="M15 18l-6-6 6-6" />
        </svg>

        <span>Previous</span>
    </button>

    <div class="flex items-center gap-3">

        <?php if ($isStudent && !$isLocked): ?>

            <span
                data-complete-hint
                class="max-w-xs text-right text-xs text-gray-400 <?= ($isCompleted || $canComplete) ? 'hidden' : '' ?>">
                <?= esc($currentItem['complete_hint'] ?? '') ?>
            </span>

            <button
                type="button"
                data-mark-complete
                data-learning-item-id="<?= esc($currentItem['id']) ?>"
                data-completed="<?= $isCompleted ? '1' : '0' ?>"
                <?= ($isCompleted || !$canComplete) ? 'disabled' : '' ?>
                class="inline-flex items-center gap-2 rounded-lg border px-5 py-2.5 text-sm font-semibold transition
                    data-[completed=1]:border-[#CFE6D5] data-[completed=1]:bg-[#E6F4EA] data-[completed=1]:text-[#5C9F72]
                    data-[completed=0]:border-[#0040A6] data-[completed=0]:text-[#0040A6] data-[completed=0]:hover:bg-[#0040A6]/10
                    data-[completed=0]:disabled:cursor-not-allowed data-[completed=0]:disabled:border-gray-300 data-[completed=0]:disabled:text-gray-400 data-[completed=0]:disabled:hover:bg-transparent">

                <span data-mark-complete-icon class="<?= $isCompleted ? '' : 'hidden' ?>">
                    <?= icon('check', 'h-4 w-4 text-current') ?>
                </span>

                <span data-mark-complete-label>
                    <?= $isCompleted ? 'Completed' : 'Mark as Completed' ?>
                </span>

            </button>

        <?php endif; ?>

        <?php if ($nextItem): ?>

            <?php if ($nextLocked): ?>
                <!-- Why Continue is disabled; completion.js removes it once the next item unlocks. -->
                <span data-continue-lock-hint class="max-w-xs text-right text-xs text-gray-400 <?= $showContinue ? '' : 'hidden' ?>">
                    <?= esc($nextLockHint) ?>
                </span>
            <?php endif; ?>

            <button
                type="button"
                data-continue
                data-next-item-id="<?= esc($nextItem['id']) ?>"
                <?= $nextLocked ? 'data-next-locked="1" disabled title="' . esc($nextLockHint, 'attr') . '"' : '' ?>
                class="learning-item-link <?= $continueClasses ?> disabled:cursor-not-allowed disabled:bg-gray-300 disabled:text-gray-500 disabled:hover:bg-gray-300 <?= $showContinue ? '' : 'hidden' ?>"
                data-learning-item="<?= esc($nextItem['id']) ?>"
            >
                <span data-continue-lock-icon class="<?= $nextLocked ? '' : 'hidden' ?>">
                    <?= icon('lock', 'h-4 w-4 text-current') ?>
                </span>

                <span>Continue</span>

                <svg
                    class="h-4 w-4"
                    xmlns="http://www.w3.org/2000/svg"
                    viewBox="0 0 24 24"
                    fill="none"
                    stroke="currentColor"
                    stroke-width="2.5"
                    stroke-linecap="round"
                    stroke-linejoin="round"
                >
                    <path d="M9 18l6-6-6-6" />
                </svg>
            </button>

        <?php elseif ($isStudent): ?>

            <button
                type="button"
                data-continue
                data-back-to-modules
                class="<?= $continueClasses ?> <?= $showContinue ? '' : 'hidden' ?>">
                <span>Back to Modules</span>
            </button>

        <?php else: ?>

            <button
                type="button"
                class="inline-flex items-center gap-2 rounded-xl bg-[#0040A6] px-5 py-2.5 text-sm font-semibold text-white opacity-50 cursor-not-allowed"
                disabled>
                <span>Continue</span>
            </button>

        <?php endif; ?>

    </div>

</footer>
