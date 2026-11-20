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

// Quiz only: an assignment shows none of these (and the server stores none of them for one).
$isQuiz = strtolower((string) ($currentAssessment['assessment_type'] ?? '')) === 'quiz';
$randomizeQuestions = (bool) ($currentSettings['randomize_questions'] ?? false);
$assignToSpecific = (bool) ($currentSettings['assign_to_specific_students'] ?? false);
$assigneeCount = (int) ($currentSettings['assignee_count'] ?? 0);
$requirePassword = (bool) ($currentSettings['require_password'] ?? false);
// The password is a hash the page never gets: only whether one exists.
$hasPassword = (bool) ($currentSettings['has_password'] ?? false);
$detectTabSwitch = (bool) ($currentSettings['detect_tab_switch'] ?? false);
$browserLockdown = (bool) ($currentSettings['browser_lockdown'] ?? false);
?>

<?php
// The pane beside the content from lg up; a side panel (the top bar's "Settings") at md–lg; below md a "Settings"
// section stacked under the content (data-side-panel-stack). The summary that folds it shows only on phones.
$field = 'w-full min-w-0 rounded-lg border border-gray-300 px-3 py-2 text-base outline-none transition focus:border-brand sm:text-sm pointer-coarse:min-h-11';
$switchLabel = 'relative inline-flex shrink-0 cursor-pointer items-center pointer-coarse:min-h-11';
?>

<aside
    id="editor-inspector-panel"
    data-side-panel="end"
    data-side-panel-stack
    aria-label="Assessment settings"
    class="flex flex-col gap-6 md:max-lg:p-5 md:max-lg:pb-safe-4 lg:w-[clamp(16rem,20%,22rem)] lg:shrink-0"
    data-assessment-id="<?= esc($currentAssessment['id']) ?>">

    <div class="flex items-center justify-between gap-3 max-md:hidden">

        <span class="font-bold text-lg text-brand">
            Assessment Settings
        </span>

        <button
            type="button"
            data-side-panel-close
            aria-label="Close settings"
            class="flex h-11 w-11 items-center justify-center rounded-lg text-[#646464] transition hover:bg-gray-100 lg:hidden">
            <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
            </svg>
        </button>

    </div>

    <details open data-inspector-settings class="group/settings">

    <summary class="flex min-h-11 cursor-pointer list-none items-center justify-between gap-3 text-lg font-bold text-brand md:hidden [&::-webkit-details-marker]:hidden">
        Settings
        <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 transition-transform group-open/settings:rotate-180" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7" />
        </svg>
    </summary>

    <div class="flex flex-col gap-5 rounded-2xl border border-gray-200 bg-white p-5 max-md:mt-3">

        <!-- Due Date -->
        <div class="flex flex-col gap-1.5">

            <label for="inspector-due-date" class="text-xs font-semibold uppercase tracking-wide text-gray-500">
                Due Date
            </label>

            <input
                type="datetime-local"
                id="inspector-due-date"
                data-inspector-field="due_date"
                value="<?= esc($dueDate) ?>"
                class="<?= $field ?>">

        </div>

        <!-- Total Points -->
        <div class="flex flex-col gap-1.5">

            <label for="inspector-total-points" class="text-xs font-semibold uppercase tracking-wide text-gray-500">
                Total Points
            </label>

            <input
                type="number"
                inputmode="numeric"
                id="inspector-total-points"
                min="0"
                step="1"
                data-inspector-field="total_points"
                data-inspector-total-points
                value="<?= esc($totalPoints) ?>"
                <?= $totalPointsSource ? 'disabled data-inspector-total-points-source="' . esc($totalPointsSource) . '"' : '' ?>
                class="<?= $field ?> disabled:cursor-not-allowed disabled:bg-gray-100 disabled:text-gray-500">

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

            <label for="inspector-duration" class="text-xs font-semibold uppercase tracking-wide text-gray-500">
                Duration
            </label>

            <div class="relative">

                <input
                    type="number"
                    inputmode="numeric"
                    id="inspector-duration"
                    min="0"
                    step="1"
                    data-inspector-field="duration"
                    value="<?= esc($currentAssessment['duration'] ?? '') ?>"
                    placeholder="No limit"
                    class="<?= $field ?> pr-14">

                <span class="pointer-events-none absolute right-3 top-1/2 -translate-y-1/2 text-sm text-[#646464]">
                    min
                </span>

            </div>

            <span class="text-xs text-[#646464]">
                Leave empty for no time limit. Starts the countdown timer once a student begins the quiz.
            </span>

        </div>

        <!-- Passing Percentage -->
        <div class="flex flex-col gap-1.5">

            <label for="inspector-passing-percentage" class="text-xs font-semibold uppercase tracking-wide text-gray-500">
                Passing Percentage
            </label>

            <div class="relative">

                <input
                    type="number"
                    inputmode="numeric"
                    id="inspector-passing-percentage"
                    min="0"
                    max="100"
                    step="1"
                    data-inspector-field="passing_percentage"
                    data-inspector-passing-percentage
                    value="<?= esc($passingPercentage ?? '') ?>"
                    class="<?= $field ?> pr-8">

                <span class="pointer-events-none absolute right-3 top-1/2 -translate-y-1/2 text-sm text-[#646464]">
                    %
                </span>

            </div>

        </div>

        <!-- Passing Score (computed) -->
        <div class="flex flex-col gap-1.5">

            <label for="inspector-passing-score" class="text-xs font-semibold uppercase tracking-wide text-gray-500">
                Passing Score
            </label>

            <input
                type="text"
                id="inspector-passing-score"
                disabled
                readonly
                data-inspector-passing-score
                value="<?= $passingScore !== null ? esc(rtrim(rtrim(number_format((float) $passingScore, 2, '.', ''), '0'), '.')) : '' ?>"
                class="<?= $field ?> cursor-not-allowed bg-gray-50 text-gray-500">

            <span class="text-xs text-[#646464]">
                Calculated from total points × passing percentage.
            </span>

        </div>

        <div class="h-px bg-gray-200"></div>

        <!-- Allow Multiple Attempts -->
        <div class="flex items-center justify-between gap-3">

            <label for="inspector-allow-multiple-attempts" class="text-sm font-medium text-gray-700">
                Allow Multiple Attempts
            </label>

            <label class="<?= $switchLabel ?>">

                <input
                    type="checkbox"
                    id="inspector-allow-multiple-attempts"
                    data-inspector-field="allow_multiple_attempts"
                    data-inspector-allow-multiple-attempts
                    class="peer sr-only"
                    <?= $allowMultipleAttempts ? 'checked' : '' ?>>

                <div class="h-6 w-11 rounded-full bg-gray-300 transition-colors peer-checked:bg-brand"></div>

                <div class="absolute left-1 top-1/2 h-4 w-4 -translate-y-1/2 rounded-full bg-white transition-transform peer-checked:translate-x-5"></div>

            </label>

        </div>

        <!-- Attempt Limit -->
        <div class="flex flex-col gap-1.5">

            <label for="inspector-attempt-limit" class="text-xs font-semibold uppercase tracking-wide text-gray-500">
                Attempt Limit
            </label>

            <input
                type="number"
                inputmode="numeric"
                id="inspector-attempt-limit"
                min="1"
                step="1"
                data-inspector-field="attempt_limit"
                data-inspector-attempt-limit
                value="<?= esc($attemptLimit) ?>"
                <?= $allowMultipleAttempts ? '' : 'disabled' ?>
                class="<?= $field ?> disabled:cursor-not-allowed disabled:bg-gray-50 disabled:text-gray-400">

        </div>

        <div class="h-px bg-gray-200"></div>

        <!-- Available From -->
        <div class="flex flex-col gap-1.5">

            <label for="inspector-available-from" class="text-xs font-semibold uppercase tracking-wide text-gray-500">
                Available From
            </label>

            <input
                type="datetime-local"
                id="inspector-available-from"
                data-inspector-field="available_from"
                value="<?= esc($availableFrom) ?>"
                class="<?= $field ?>">

        </div>

        <!-- Available Until -->
        <div class="flex flex-col gap-1.5">

            <label for="inspector-available-until" class="text-xs font-semibold uppercase tracking-wide text-gray-500">
                Available Until
            </label>

            <input
                type="datetime-local"
                id="inspector-available-until"
                data-inspector-field="available_until"
                value="<?= esc($availableUntil) ?>"
                class="<?= $field ?>">

        </div>

        <div class="h-px bg-gray-200"></div>

        <!-- Allow Late Submission -->
        <div class="flex items-center justify-between gap-3">

            <label for="inspector-allow-late-submission" class="text-sm font-medium text-gray-700">
                Allow Late Submission
            </label>

            <label class="<?= $switchLabel ?>">

                <input
                    type="checkbox"
                    id="inspector-allow-late-submission"
                    data-inspector-field="allow_late_submission"
                    class="peer sr-only"
                    <?= $allowLateSubmission ? 'checked' : '' ?>>

                <div class="h-6 w-11 rounded-full bg-gray-300 transition-colors peer-checked:bg-brand"></div>

                <div class="absolute left-1 top-1/2 h-4 w-4 -translate-y-1/2 rounded-full bg-white transition-transform peer-checked:translate-x-5"></div>

            </label>

        </div>

        <div class="h-px bg-gray-200"></div>

        <!-- Specific students (every assessment) -->
        <div
            data-specific-students
            data-assignee-count="<?= $assigneeCount ?>"
            class="flex flex-col gap-3">

            <div class="flex items-start justify-between gap-3">

                <div class="flex flex-col gap-0.5">
                    <span class="text-sm font-medium text-gray-700">Specific students</span>
                    <span class="text-xs text-[#646464]">Give this only to the students you choose. The others do not see it.</span>
                </div>

                <label class="<?= $switchLabel ?>">

                    <input
                        type="checkbox"
                        aria-label="Specific students"
                        data-inspector-field="assign_to_specific_students"
                        data-specific-toggle
                        class="peer sr-only"
                        <?= $assignToSpecific ? 'checked' : '' ?>>

                    <div class="h-6 w-11 rounded-full bg-gray-300 transition-colors peer-checked:bg-brand"></div>

                    <div class="absolute left-1 top-1/2 h-4 w-4 -translate-y-1/2 rounded-full bg-white transition-transform peer-checked:translate-x-5"></div>

                </label>

            </div>

            <div data-specific-state class="<?= $assignToSpecific ? '' : 'hidden' ?> flex items-center gap-2">

                <span data-specific-count class="rounded-lg bg-gray-50 px-3 py-1.5 text-sm text-gray-700">
                    <?= $assigneeCount ?> <?= $assigneeCount === 1 ? 'student' : 'students' ?> selected
                </span>

                <button
                    type="button"
                    data-specific-open
                    aria-label="Choose students"
                    title="Choose students"
                    class="flex items-center justify-center rounded-md border border-brand p-1.5 text-brand transition hover:bg-brand-tint pointer-coarse:min-h-11 pointer-coarse:min-w-11">

                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M15 19.128a9.38 9.38 0 002.625.372 9.337 9.337 0 004.121-.952 4.125 4.125 0 00-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 018.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0111.964-3.07M12 6.375a3.375 3.375 0 11-6.75 0 3.375 3.375 0 016.75 0zm8.25 2.25a2.625 2.625 0 11-5.25 0 2.625 2.625 0 015.25 0z" />
                    </svg>

                </button>

            </div>

        </div>

        <?php if ($isQuiz) : ?>

            <div class="h-px bg-gray-200"></div>

            <!-- Randomize questions (quiz only) -->
            <div class="flex items-start justify-between gap-3">

                <div class="flex flex-col gap-0.5">
                    <span class="text-sm font-medium text-gray-700">Randomize questions</span>
                    <span class="text-xs text-[#646464]">Each student gets the questions in their own order.</span>
                </div>

                <label class="<?= $switchLabel ?>">

                    <input
                        type="checkbox"
                        aria-label="Randomize questions"
                        data-inspector-field="randomize_questions"
                        class="peer sr-only"
                        <?= $randomizeQuestions ? 'checked' : '' ?>>

                    <div class="h-6 w-11 rounded-full bg-gray-300 transition-colors peer-checked:bg-brand"></div>

                    <div class="absolute left-1 top-1/2 h-4 w-4 -translate-y-1/2 rounded-full bg-white transition-transform peer-checked:translate-x-5"></div>

                </label>

            </div>

            <div class="h-px bg-gray-200"></div>

            <!-- Password (quiz only) -->
            <div
                data-quiz-password
                data-has-password="<?= $hasPassword ? '1' : '0' ?>"
                class="flex flex-col gap-3">

                <div class="flex items-start justify-between gap-3">

                    <div class="flex flex-col gap-0.5">
                        <span class="text-sm font-medium text-gray-700">Require a password</span>
                        <span class="text-xs text-[#646464]">Students enter it before the quiz starts.</span>
                    </div>

                <label class="<?= $switchLabel ?>">

                    <input
                        type="checkbox"
                        aria-label="Require a password"
                        data-inspector-field="require_password"
                        data-password-toggle
                        class="peer sr-only"
                        <?= $requirePassword ? 'checked' : '' ?>>

                    <div class="h-6 w-11 rounded-full bg-gray-300 transition-colors peer-checked:bg-brand"></div>

                    <div class="absolute left-1 top-1/2 h-4 w-4 -translate-y-1/2 rounded-full bg-white transition-transform peer-checked:translate-x-5"></div>

                </label>

                </div>

                <div data-password-state class="<?= $requirePassword ? '' : 'hidden' ?>">

                    <div class="flex items-center gap-2">

                        <button
                            type="button"
                            data-password-create
                            class="<?= $hasPassword ? 'hidden ' : '' ?>w-fit rounded-lg border border-brand px-3 py-1.5 text-xs font-semibold text-brand transition hover:bg-brand-tint pointer-coarse:min-h-11">
                            <span class="inline-flex items-center gap-1.5">
                                <svg xmlns="http://www.w3.org/2000/svg" class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                                </svg>
                                Create password
                            </span>
                        </button>

                        <div data-password-set class="<?= $hasPassword ? '' : 'hidden' ?>">
                            <div class="flex items-center gap-2 rounded-lg bg-gray-50 px-3 py-1.5">

                                <span data-password-mask class="font-mono text-sm tracking-widest text-gray-700">******</span>

                                <button
                                    type="button"
                                    data-password-edit
                                    aria-label="Edit password"
                                    title="Edit password"
                                    class="flex items-center justify-center rounded-md p-1 text-gray-500 transition hover:bg-gray-200 hover:text-brand pointer-coarse:min-h-11 pointer-coarse:min-w-11">

                                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L10.582 16.07a4.5 4.5 0 01-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 011.13-1.897l8.932-8.931z" />
                                    </svg>

                                </button>

                            </div>
                        </div>

                    </div>

                </div>

            </div>

            <!-- Additional security (quiz only, expandable) -->
            <details
                data-additional-security
                class="group rounded-xl border border-gray-200 bg-gray-50 p-3"
                <?= $detectTabSwitch || $browserLockdown ? 'open' : '' ?>>

                <summary class="flex cursor-pointer list-none items-center justify-between text-sm font-medium text-gray-700 pointer-coarse:min-h-11 [&::-webkit-details-marker]:hidden">

                    Additional security

                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 text-gray-500 transition-transform group-open:rotate-180" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7" />
                    </svg>

                </summary>

                <div class="mt-3 flex flex-col gap-4">

                    <div class="flex items-start justify-between gap-3">

                        <div class="flex flex-col gap-0.5">
                            <span class="text-sm font-medium text-gray-700">Detect when a student leaves the quiz</span>
                            <span class="text-xs text-[#646464]">
                                Records when they leave the quiz page and for how long, and shows it beside their attempt.
                                A web page cannot see which tab or site they went to.
                            </span>
                        </div>

                <label class="<?= $switchLabel ?>">

                    <input
                        type="checkbox"
                        aria-label="Detect when a student leaves the quiz"
                        data-inspector-field="detect_tab_switch"
                        class="peer sr-only"
                        <?= $detectTabSwitch ? 'checked' : '' ?>>

                    <div class="h-6 w-11 rounded-full bg-gray-300 transition-colors peer-checked:bg-brand"></div>

                    <div class="absolute left-1 top-1/2 h-4 w-4 -translate-y-1/2 rounded-full bg-white transition-transform peer-checked:translate-x-5"></div>

                </label>

                    </div>

                    <div class="flex items-start justify-between gap-3">

                        <div class="flex flex-col gap-0.5">
                            <span class="text-sm font-medium text-gray-700">Lock the browser</span>
                            <span class="text-xs text-[#646464]">
                                Asks the student's browser for fullscreen and blocks copying, pasting, printing and the
                                right-click menu while they answer, and records each attempt to do so. It discourages
                                leaving the quiz; it cannot prevent it.
                            </span>
                        </div>

                <label class="<?= $switchLabel ?>">

                    <input
                        type="checkbox"
                        aria-label="Lock the browser"
                        data-inspector-field="browser_lockdown"
                        class="peer sr-only"
                        <?= $browserLockdown ? 'checked' : '' ?>>

                    <div class="h-6 w-11 rounded-full bg-gray-300 transition-colors peer-checked:bg-brand"></div>

                    <div class="absolute left-1 top-1/2 h-4 w-4 -translate-y-1/2 rounded-full bg-white transition-transform peer-checked:translate-x-5"></div>

                </label>

                    </div>

                </div>

            </details>

        <?php endif; ?>

    </div>

    </details>

</aside>
