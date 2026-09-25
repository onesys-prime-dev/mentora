<?php

/*
|--------------------------------------------------------------------------
| A student's attempts at a quiz ("Attempt History") or submissions to an
| assignment ("Submission History"), newest first. Assignment rows expand
| to show what was handed in; quiz rows show time taken.
|
| Data: $historyAttempts (assessment_submissions rows, newest first; for
| assignments each has a `file`), $historyType ('quiz'|'assignment'),
| $totalPoints, $countedAttemptId (the attempt that counts, if any).
|--------------------------------------------------------------------------
*/

$historyAttempts ??= [];

// The attempt that counts toward the grade (AssessmentResultService).
$countedAttemptId = (int) ($countedAttemptId ?? 0);

$isQuizHistory = ($historyType ?? 'assignment') === 'quiz';

$totalPoints = (int) ($totalPoints ?? 0);

$statusMeta = static fn (?string $status) => match ($status) {
    'In Progress' => ['label' => 'In Progress', 'class' => 'bg-[#FFF1D6] text-[#946200]'],
    'Submitted' => ['label' => 'Submitted', 'class' => 'bg-[#0040A6]/10 text-[#0040A6]'],
    'Partially Graded' => ['label' => 'Partially Graded', 'class' => 'bg-[#FFF1D6] text-[#946200]'],
    'Grading' => ['label' => 'Being Graded', 'class' => 'bg-[#FFF1D6] text-[#946200]'],
    'Graded' => ['label' => 'Graded', 'class' => 'bg-emerald-100 text-emerald-700'],
    'Returned' => ['label' => 'Returned', 'class' => 'bg-gray-100 text-gray-500'],
    'Cancelled' => ['label' => 'Cancelled', 'class' => 'bg-red-50 text-red-500'],
    default => ['label' => 'Not Submitted', 'class' => 'bg-gray-100 text-gray-400'],
};

$formatDate = static fn (?string $datetime) => $datetime ? date('M j, Y g:i A', strtotime($datetime)) : '';

$timeTaken = static function (array $attempt): ?string {

    if (empty($attempt['started_at']) || empty($attempt['submitted_at'])) {
        return null;
    }

    $seconds = max(0, strtotime($attempt['submitted_at']) - strtotime($attempt['started_at']));

    return $seconds >= 3600
        ? floor($seconds / 3600) . 'h ' . floor(($seconds % 3600) / 60) . 'm'
        : max(1, (int) round($seconds / 60)) . ' min';
};
?>

<div class="flex flex-col gap-3">

    <span class="font-bold text-md text-[#0040A6]">
        <?= $isQuizHistory ? 'Attempt History' : 'Submission History' ?>
    </span>

    <?php if (empty($historyAttempts)): ?>

        <span class="text-sm italic text-[#5B5B5B]">
            <?= $isQuizHistory ? "You haven't taken this quiz yet." : "You haven't submitted this assignment yet." ?>
        </span>

    <?php else: ?>

        <div class="flex flex-col gap-2">

            <?php foreach ($historyAttempts as $index => $attempt): ?>

                <?php
                    $meta = $statusMeta($attempt['status'] ?? null);

                    $inProgress = ($attempt['status'] ?? '') === 'In Progress';

                    $details = [];

                    $details[] = $inProgress
                        ? 'Started ' . $formatDate($attempt['started_at'] ?? null)
                        : $formatDate($attempt['submitted_at'] ?? null);

                    if ($isQuizHistory && !$inProgress && ($taken = $timeTaken($attempt))) {
                        $details[] = $taken;
                    }

                    if (!empty($attempt['is_late_submission'])) {
                        $details[] = 'Late';
                    }
                ?>

                <?php if ($isQuizHistory): ?>
                    <div class="flex items-center justify-between gap-3 rounded-xl border border-gray-200 bg-white px-4 py-3">
                <?php else: ?>
                    <details class="group rounded-xl border border-gray-200 bg-white" <?= $index === 0 ? 'open' : '' ?>>
                        <summary class="flex cursor-pointer list-none items-center justify-between gap-3 px-4 py-3">
                <?php endif; ?>

                            <div class="flex items-center gap-3">

                                <?php if (!$isQuizHistory): ?>
                                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 text-gray-400 transition-transform group-open:rotate-90" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7" />
                                    </svg>
                                <?php endif; ?>

                                <span class="text-sm font-semibold text-[#1F2937]">
                                    Attempt <?= esc((int) $attempt['attempt_number']) ?>
                                </span>

                                <span class="text-xs text-gray-400">
                                    <?= esc(implode(' · ', array_filter($details))) ?>
                                </span>

                            </div>

                            <div class="flex items-center gap-2">

                                <?php if ($countedAttemptId > 0 && (int) $attempt['id'] === $countedAttemptId && count($historyAttempts) > 1): ?>
                                    <span class="rounded-full bg-[#0040A6] px-2.5 py-0.5 text-[10px] font-bold text-white">
                                        Counts toward your grade
                                    </span>
                                <?php endif; ?>

                                <?php if (!empty($attempt['is_graded'])): ?>
                                    <span class="text-xs font-bold text-[#0040A6]">
                                        <?= esc((int) $attempt['score']) ?>/<?= esc($totalPoints) ?>
                                    </span>
                                <?php elseif (($attempt['status'] ?? '') === 'Partially Graded'): ?>
                                    <!-- Auto-graded part; the rest is still being graded -->
                                    <span class="text-xs font-bold text-[#946200]">
                                        <?= esc((int) $attempt['score']) ?>/<?= esc($totalPoints) ?> so far
                                    </span>
                                <?php endif; ?>

                                <span class="rounded-full px-2.5 py-0.5 text-[10px] font-bold <?= $meta['class'] ?>">
                                    <?= esc($meta['label']) ?>
                                </span>

                            </div>

                <?php if ($isQuizHistory): ?>
                    </div>
                <?php else: ?>
                        </summary>

                        <div class="border-t border-gray-100 p-4">
                            <?= view('pages/courses/features/assessments/partials/submitted-work', ['attempt' => $attempt]) ?>
                        </div>
                    </details>
                <?php endif; ?>

            <?php endforeach; ?>

        </div>

    <?php endif; ?>

</div>
