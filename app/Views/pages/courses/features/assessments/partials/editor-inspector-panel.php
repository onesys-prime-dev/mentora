<?php

$dueDate = $currentAssessment['due_date']
    ? date('Y-m-d\TH:i', strtotime($currentAssessment['due_date']))
    : '';

$availableFrom = $currentAssessment['available_from']
    ? date('Y-m-d\TH:i', strtotime($currentAssessment['available_from']))
    : '';

$availableUntil = $currentAssessment['available_until']
    ? date('Y-m-d\TH:i', strtotime($currentAssessment['available_until']))
    : '';

$totalPoints = (float) ($currentAssessment['total_points'] ?? 0);
$totalPointsSource = $totalPointsSource ?? null;
$passingPercentage = $currentAssessment['passing_percentage'] ?? null;
$passingScore = $currentAssessment['passing_score'] ?? null;

$allowMultipleAttempts = (bool) ($currentSettings['allow_multiple_attempts'] ?? false);
$attemptLimit = (int) ($currentAssessment['attempt_limit'] ?? 1);
$allowLateSubmission = (bool) ($currentSettings['allow_late_submission'] ?? false);
?>

<div
    id="editor-inspector-panel"
    class="w-1/5 flex flex-col gap-6"
    data-assessment-id="<?= esc($currentAssessment['id']) ?>">

    <span class="font-bold text-lg text-[#0040A6]">
        Assessment Settings
    </span>

    <div class="flex flex-col gap-5 rounded-2xl border border-gray-200 bg-white p-5">

        <!-- Due Date -->
        <div class="flex flex-col gap-1.5">

            <label class="text-xs font-semibold uppercase tracking-wide text-gray-500">
                Due Date
            </label>

            <input
                type="datetime-local"
                data-inspector-field="due_date"
                value="<?= esc($dueDate) ?>"
                class="rounded-lg border border-gray-300 px-3 py-2 text-sm outline-none transition focus:border-[#0040A6]">

        </div>

        <!-- Total Points -->
        <div class="flex flex-col gap-1.5">

            <label class="text-xs font-semibold uppercase tracking-wide text-gray-500">
                Total Points
            </label>

            <input
                type="number"
                min="0"
                step="1"
                data-inspector-field="total_points"
                data-inspector-total-points
                value="<?= esc($totalPoints) ?>"
                <?= $totalPointsSource ? 'disabled data-inspector-total-points-source="' . esc($totalPointsSource) . '"' : '' ?>
                class="rounded-lg border border-gray-300 px-3 py-2 text-sm outline-none transition focus:border-[#0040A6] disabled:cursor-not-allowed disabled:bg-gray-100 disabled:text-gray-500">

            <?php if ($totalPointsSource) : ?>
                <p class="text-xs text-gray-500" data-inspector-total-points-hint>
                    <?= $totalPointsSource === 'quiz'
                        ? 'Worked out from the points of the questions in the quiz builder.'
                        : 'Worked out from the grading rubric.' ?>
                </p>
            <?php endif; ?>

        </div>

        <!-- Duration (Timer) -->
        <div class="flex flex-col gap-1.5">

            <label class="text-xs font-semibold uppercase tracking-wide text-gray-500">
                Duration
            </label>

            <div class="relative">

                <input
                    type="number"
                    min="0"
                    step="1"
                    data-inspector-field="duration"
                    value="<?= esc($currentAssessment['duration'] ?? '') ?>"
                    placeholder="No limit"
                    class="w-full rounded-lg border border-gray-300 px-3 py-2 pr-14 text-sm outline-none transition focus:border-[#0040A6]">

                <span class="pointer-events-none absolute right-3 top-1/2 -translate-y-1/2 text-sm text-[#767676]">
                    min
                </span>

            </div>

            <span class="text-xs text-[#767676]">
                Leave empty for no time limit. Starts the countdown timer once a student begins the quiz.
            </span>

        </div>

        <!-- Passing Percentage -->
        <div class="flex flex-col gap-1.5">

            <label class="text-xs font-semibold uppercase tracking-wide text-gray-500">
                Passing Percentage
            </label>

            <div class="relative">

                <input
                    type="number"
                    min="0"
                    max="100"
                    step="1"
                    data-inspector-field="passing_percentage"
                    data-inspector-passing-percentage
                    value="<?= esc($passingPercentage ?? '') ?>"
                    class="w-full rounded-lg border border-gray-300 px-3 py-2 pr-8 text-sm outline-none transition focus:border-[#0040A6]">

                <span class="pointer-events-none absolute right-3 top-1/2 -translate-y-1/2 text-sm text-[#767676]">
                    %
                </span>

            </div>

        </div>

        <!-- Passing Score (computed) -->
        <div class="flex flex-col gap-1.5">

            <label class="text-xs font-semibold uppercase tracking-wide text-gray-500">
                Passing Score
            </label>

            <input
                type="text"
                disabled
                readonly
                data-inspector-passing-score
                value="<?= $passingScore !== null ? esc(rtrim(rtrim(number_format((float) $passingScore, 2, '.', ''), '0'), '.')) : '' ?>"
                class="cursor-not-allowed rounded-lg border border-gray-200 bg-gray-50 px-3 py-2 text-sm text-gray-500 outline-none">

            <span class="text-xs text-[#767676]">
                Calculated from total points × passing percentage.
            </span>

        </div>

        <div class="h-px bg-gray-200"></div>

        <!-- Allow Multiple Attempts -->
        <div class="flex items-center justify-between gap-3">

            <label class="text-sm font-medium text-gray-700">
                Allow Multiple Attempts
            </label>

            <label class="relative inline-flex shrink-0 cursor-pointer items-center">

                <input
                    type="checkbox"
                    data-inspector-field="allow_multiple_attempts"
                    data-inspector-allow-multiple-attempts
                    class="peer sr-only"
                    <?= $allowMultipleAttempts ? 'checked' : '' ?>>

                <div class="h-6 w-11 rounded-full bg-gray-300 transition-colors peer-checked:bg-[#0040A6]"></div>

                <div class="absolute left-1 top-1 h-4 w-4 rounded-full bg-white transition-transform peer-checked:translate-x-5"></div>

            </label>

        </div>

        <!-- Attempt Limit -->
        <div class="flex flex-col gap-1.5">

            <label class="text-xs font-semibold uppercase tracking-wide text-gray-500">
                Attempt Limit
            </label>

            <input
                type="number"
                min="1"
                step="1"
                data-inspector-field="attempt_limit"
                data-inspector-attempt-limit
                value="<?= esc($attemptLimit) ?>"
                <?= $allowMultipleAttempts ? '' : 'disabled' ?>
                class="rounded-lg border border-gray-300 px-3 py-2 text-sm outline-none transition focus:border-[#0040A6] disabled:cursor-not-allowed disabled:bg-gray-50 disabled:text-gray-400">

        </div>

        <div class="h-px bg-gray-200"></div>

        <!-- Available From -->
        <div class="flex flex-col gap-1.5">

            <label class="text-xs font-semibold uppercase tracking-wide text-gray-500">
                Available From
            </label>

            <input
                type="datetime-local"
                data-inspector-field="available_from"
                value="<?= esc($availableFrom) ?>"
                class="rounded-lg border border-gray-300 px-3 py-2 text-sm outline-none transition focus:border-[#0040A6]">

        </div>

        <!-- Available Until -->
        <div class="flex flex-col gap-1.5">

            <label class="text-xs font-semibold uppercase tracking-wide text-gray-500">
                Available Until
            </label>

            <input
                type="datetime-local"
                data-inspector-field="available_until"
                value="<?= esc($availableUntil) ?>"
                class="rounded-lg border border-gray-300 px-3 py-2 text-sm outline-none transition focus:border-[#0040A6]">

        </div>

        <div class="h-px bg-gray-200"></div>

        <!-- Allow Late Submission -->
        <div class="flex items-center justify-between gap-3">

            <label class="text-sm font-medium text-gray-700">
                Allow Late Submission
            </label>

            <label class="relative inline-flex shrink-0 cursor-pointer items-center">

                <input
                    type="checkbox"
                    data-inspector-field="allow_late_submission"
                    class="peer sr-only"
                    <?= $allowLateSubmission ? 'checked' : '' ?>>

                <div class="h-6 w-11 rounded-full bg-gray-300 transition-colors peer-checked:bg-[#0040A6]"></div>

                <div class="absolute left-1 top-1 h-4 w-4 rounded-full bg-white transition-transform peer-checked:translate-x-5"></div>

            </label>

        </div>

    </div>

</div>
