<?php

$totalPoints = (float) ($currentAssessment['total_points'] ?? 0);

// The attempt on screen (a tab may pick an attempt other than the default).
$submissionStatus = $submission['status'] ?? $selectedStudent['submission_status'] ?? null;

$statusMeta = match ($submissionStatus) {
    'Submitted' => ['label' => 'Ready for Grading', 'class' => 'bg-brand/10 text-brand', 'canExpand' => true],
    'Partially Graded' => ['label' => 'Partially Graded', 'class' => 'bg-[#FFF1D6] text-[#946200]', 'canExpand' => true],
    'Grading' => ['label' => 'Grading In Progress', 'class' => 'bg-[#FFF1D6] text-[#946200]', 'canExpand' => true],
    'Graded' => ['label' => 'Graded', 'class' => 'bg-[#E8F5EC] text-[#2E7D4F]', 'canExpand' => true],
    'Returned' => ['label' => 'Returned', 'class' => 'bg-gray-100 text-gray-500', 'canExpand' => false],
    'Cancelled' => ['label' => 'Cancelled', 'class' => 'bg-red-50 text-red-500', 'canExpand' => false],
    'In Progress' => ['label' => 'Not Submitted Yet', 'class' => 'bg-gray-100 text-[#767676]', 'canExpand' => false],
    default => ['label' => 'No Submission', 'class' => 'bg-gray-100 text-[#767676]', 'canExpand' => false],
};

$existingScore = $submission['score'] ?? null;

$existingRubricScores = $submission['rubric_scores'] ?? [];

$existingGradingMode = $submission['grading_mode'] ?? 'Default';

/*
|--------------------------------------------------------------------------
| Grading panel state:
|   collapsed → Submitted: "Grade Work" expands it (and moves it to Grading)
|   editing   → Grading: open and editable, "Submit Grade"
|   locked    → Graded: open and read-only; only the edit button, which
|               asks for confirmation before reopening the grade
|   disabled  → nothing gradable (no submission, in progress, returned…)
|--------------------------------------------------------------------------
*/

// An instructor whose institution took grading away (grading.manage) reads the work and its grade, nothing more.
$gradingViewOnly = \App\Services\Courses\CourseAbilities::viewOnly(auth()->user(), 'grading');

$panelState = match (true) {
    !$submission => 'disabled',
    $gradingViewOnly && $submissionStatus === 'Graded' => 'locked',
    $gradingViewOnly => 'disabled',
    $submissionStatus === 'Graded' => 'locked',
    $submissionStatus === 'Grading' => 'editing',
    in_array($submissionStatus, ['Submitted', 'Partially Graded'], true) => 'collapsed',
    default => 'disabled',
};

$isLocked = $panelState === 'locked';

$showRubricMode = $currentRubric && $existingGradingMode === 'Rubric';

$attemptTabs ??= [];
?>

<div class="flex min-w-0 flex-1 flex-col p-10">

    <div class="flex items-center justify-between border-b border-gray-200 pb-4">

        <div class="flex items-center gap-3">

            <button
                type="button"
                data-back-to-assessments
                data-assessment-id="<?= esc($currentAssessment['id']) ?>"
                class="rounded-lg p-2 text-gray-500 transition hover:bg-gray-100">

                <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7" />
                </svg>

            </button>

            <div class="flex items-center gap-2">

                <span class="text-xl font-bold text-brand">
                    Grade Work
                </span>

                <span class="text-gray-300">
                    ·
                </span>

                <span class="text-lg font-semibold text-[#646464]">
                    <?= esc($currentAssessment['title']) ?>
                </span>

            </div>

        </div>

        <div
            data-grade-work-save-status
            class="flex items-center gap-2 text-sm text-gray-500">

            <div class="h-2 w-2 rounded-full bg-emerald-500" data-grade-work-save-indicator></div>

            <span data-grade-work-save-message>All changes saved</span>

        </div>

    </div>

    <div class="flex flex-1 items-start gap-6 py-6">

        <div class="sticky top-6 flex w-[290px] shrink-0 flex-col gap-3 self-start">

            <div class="flex items-center justify-between">

                <span class="text-xs font-bold uppercase tracking-wide text-[#767676]">
                    Students
                </span>

                <div class="flex items-center gap-2">

                    <select
                        data-grade-work-status-filter
                        class="rounded-lg border border-gray-200 px-2 py-1 text-xs text-gray-600 outline-none focus:border-brand">

                        <option value="">All</option>
                        <option value="Submitted">Submitted</option>
                        <option value="Partially Graded">Partially Graded</option>
                        <option value="Grading">Grading</option>
                        <option value="Graded">Graded</option>
                        <option value="Returned">Returned</option>
                        <option value="In Progress">In Progress</option>
                        <option value="">No Submission</option>

                    </select>

                    <button
                        type="button"
                        data-sort-students
                        title="Sort by name" aria-label="Sort by name"
                        class="rounded-lg border border-gray-200 p-1.5 text-gray-500 transition hover:bg-gray-100">

                        <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M3 4h13M3 8h9M3 12h5m6-4v12m0 0l-3-3m3 3l3-3" />
                        </svg>

                    </button>

                </div>

            </div>

            <div
                data-grade-work-student-list
                data-selected-student-id="<?= esc($selectedStudentId ?? '') ?>"
                class="flex flex-col gap-2">

                <?php foreach (($students ?? []) as $student): ?>

                    <?php
                        $rowStatus = $student['submission_status'] ?? null;

                        $rowMeta = match ($rowStatus) {
                            'Submitted' => ['label' => 'Submitted', 'class' => 'bg-brand/10 text-brand'],
                            'Partially Graded' => ['label' => 'Partially Graded', 'class' => 'bg-[#FFF1D6] text-[#946200]'],
                            'Grading' => ['label' => 'Grading', 'class' => 'bg-[#FFF1D6] text-[#946200]'],
                            'Graded' => ['label' => 'Graded', 'class' => 'bg-[#E8F5EC] text-[#2E7D4F]'],
                            'Returned' => ['label' => 'Returned', 'class' => 'bg-gray-100 text-gray-500'],
                            'Cancelled' => ['label' => 'Cancelled', 'class' => 'bg-red-50 text-red-500'],
                            'In Progress' => ['label' => 'In Progress', 'class' => 'bg-gray-100 text-[#767676]'],
                            default => ['label' => 'No Submission', 'class' => 'bg-gray-100 text-[#767676]'],
                        };

                        $isActive = (int) $student['student_id'] === (int) ($selectedStudentId ?? 0);

                        $initials = '';

                        foreach (explode(' ', trim($student['student_name'] ?? '')) as $part) {
                            if ($part !== '') {
                                $initials .= mb_strtoupper(mb_substr($part, 0, 1));
                            }
                        }
                    ?>

                    <div
                        data-grade-work-student
                        data-grade-work-student-id="<?= esc($student['student_id']) ?>"
                        data-student-status="<?= esc($rowStatus ?? '') ?>"
                        data-student-name="<?= esc($student['student_name']) ?>"
                        class="flex cursor-pointer items-center gap-3 rounded-xl border p-3 transition-colors <?= $isActive ? 'border-brand bg-brand/5' : 'border-gray-200 bg-white hover:border-brand/40' ?>">

                        <div class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-brand/10 text-xs font-bold text-brand">
                            <?= esc(mb_substr($initials, 0, 2)) ?>
                        </div>

                        <div class="flex min-w-0 flex-1 flex-col">

                            <span class="truncate text-sm font-semibold text-[#1A1C1E]">
                                <?= esc($student['student_name']) ?>
                            </span>

                            <span class="rounded-full self-start px-2 py-0.5 text-[10px] font-bold <?= $rowMeta['class'] ?>">
                                <?= esc($rowMeta['label']) ?>
                            </span>

                        </div>

                        <?php if (!empty($student['is_graded'])): ?>

                            <!-- Counted score: best quiz attempt / latest graded submission -->
                            <span class="shrink-0 text-xs font-bold text-[#646464]">
                                <?= esc((int) $student['submission_score']) ?>/<?= esc((int) $totalPoints) ?>
                            </span>

                        <?php endif; ?>

                    </div>

                <?php endforeach; ?>

                <?php if (empty($students)): ?>

                    <div class="rounded-xl border border-dashed border-gray-200 p-4 text-center text-xs text-[#767676]">
                        No students enrolled.
                    </div>

                <?php endif; ?>

            </div>

        </div>

        <div class="flex min-w-0 flex-1 flex-col gap-3">

            <div
                id="student-work-container"
                class="flex-1 rounded-2xl border border-gray-100 p-6">

                <?php if (!$selectedStudent): ?>

                    <div class="flex h-full flex-col items-center justify-center gap-2 py-16 text-center">
                        <span class="text-sm font-semibold text-[#646464]">Select a student</span>
                        <span class="text-xs text-[#767676]">Choose a student from the left to view their work.</span>
                    </div>

                <?php elseif (!$submission): ?>

                    <div class="flex h-full flex-col items-center justify-center gap-2 py-16 text-center">
                        <span class="text-sm font-semibold text-[#646464]">No submission yet</span>
                        <span class="text-xs text-[#767676]"><?= esc($selectedStudent['student_name']) ?> has not submitted this assessment.</span>
                    </div>

                <?php else: ?>

                    <div class="flex flex-col gap-4">

                        <div class="flex items-center justify-between border-b border-gray-100 pb-3">

                            <div class="flex items-center gap-3">

                                <span class="font-bold text-[#1A1C1E]">
                                    <?= esc($selectedStudent['student_name']) ?>'s Work
                                </span>

                                <button
                                    type="button"
                                    data-view-student-grade="<?= esc($selectedStudent['student_id']) ?>"
                                    class="inline-flex items-center gap-1 text-xs font-semibold text-brand transition hover:underline">
                                    View full grade
                                    <svg xmlns="http://www.w3.org/2000/svg" class="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7" /></svg>
                                </button>

                            </div>

                            <span class="text-xs text-[#767676]">
                                <?= $submission['submitted_at'] ? 'Submitted ' . esc(date('F j, Y g:i A', strtotime($submission['submitted_at']))) : 'Not submitted' ?>
                                <?= !empty($submission['is_late_submission']) ? ' · Late' : '' ?>
                                · Attempt <?= esc((int) ($submission['attempt_number'] ?? 1)) ?>
                            </span>

                        </div>

                        <?php if ($attemptTabs): ?>

                            <!-- One tab per attempt; ★ marks the attempt that counts -->
                            <div class="flex flex-wrap gap-2" data-grade-work-attempt-tabs>

                                <?php foreach ($attemptTabs as $tab): ?>

                                    <button
                                        type="button"
                                        data-grade-work-attempt="<?= esc($tab['id']) ?>"
                                        <?= $tab['counts'] ? 'title="This attempt counts toward the grade"' : '' ?>
                                        class="inline-flex items-center gap-1.5 rounded-full border px-3 py-1 text-xs font-semibold transition
                                            <?= $tab['active']
                                                ? 'border-brand bg-brand text-white'
                                                : 'border-gray-200 bg-white text-gray-600 hover:border-brand/50 hover:text-brand' ?>">

                                        Attempt <?= esc($tab['number']) ?>

                                        <span class="<?= $tab['active'] ? 'text-white/80' : 'text-[#767676]' ?>">·</span>

                                        <?php if ($tab['score'] !== null): ?>
                                            <?= esc((int) $tab['score']) ?>/<?= esc((int) $totalPoints) ?>
                                        <?php elseif ($tab['needsGrading']): ?>
                                            <span class="<?= $tab['active'] ? '' : 'text-[#946200]' ?>">Needs grading</span>
                                        <?php else: ?>
                                            Not graded
                                        <?php endif; ?>

                                        <?php if ($tab['counts']): ?>
                                            <span aria-label="Counts toward the grade">★</span>
                                        <?php endif; ?>

                                    </button>

                                <?php endforeach; ?>

                            </div>

                        <?php endif; ?>

                        <?php if (!empty($questionResponses)): ?>

                            <div class="flex flex-col gap-4">

                                <?php foreach ($questionResponses as $index => $question): ?>

                                    <?php
                                    $response = $question['response'] ?? null;
                                    $canEditQuestion = $panelState === 'editing';
                                    $awarded = (float) ($response['score'] ?? 0);
                                    $awardedLabel = (float) $awarded == (int) $awarded ? (int) $awarded : $awarded;
                                    ?>

                                    <div
                                        class="rounded-xl border border-gray-100 bg-[#F9F9FC] p-4"
                                        data-grade-work-question
                                        data-question-id="<?= esc($question['id']) ?>"
                                        data-question-points="<?= esc($question['points']) ?>"
                                        data-auto-gradable="<?= !empty($question['auto_gradable']) ? '1' : '0' ?>">

                                        <div class="flex items-center gap-2 pb-2">

                                            <span class="text-xs font-bold text-[#767676]">Q<?= esc($index + 1) ?></span>

                                            <span class="rounded-full bg-gray-100 px-2 py-0.5 text-[10px] font-bold text-gray-600">
                                                <?= esc((int) $question['points']) ?> pts
                                            </span>

                                            <?php if (!empty($question['auto_gradable'])): ?>

                                                <span class="ml-auto flex items-center gap-1" data-question-badge>

                                                    <span class="rounded-full px-2 py-0.5 text-[10px] font-bold <?= $awarded > 0 ? 'bg-[#E8F5EC] text-[#2E7D4F]' : 'bg-red-50 text-red-600' ?>">
                                                        <?= $awarded > 0 ? '✓ Correct' : ($response ? '✗ Incorrect' : '✗ No answer') ?>
                                                        · <?= esc($awardedLabel) ?> pts
                                                    </span>

                                                    <?php if ($canEditQuestion): ?>
                                                        <button
                                                            type="button"
                                                            data-question-edit-toggle
                                                            title="Grade this question manually" aria-label="Grade this question manually"
                                                            class="rounded-full p-1 text-[#767676] transition hover:bg-gray-200 hover:text-gray-600">
                                                            <svg xmlns="http://www.w3.org/2000/svg" class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                                                <path stroke-linecap="round" stroke-linejoin="round" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                                                            </svg>
                                                        </button>
                                                    <?php endif; ?>

                                                </span>

                                                <?php if ($canEditQuestion): ?>

                                                    <div class="ml-auto hidden items-center gap-1.5" data-question-manual-controls>

                                                        <button type="button" data-question-mark="correct" class="rounded-full bg-emerald-100 px-2 py-0.5 text-[10px] font-bold text-emerald-700 transition hover:bg-emerald-200">
                                                            Correct
                                                        </button>

                                                        <button type="button" data-question-mark="incorrect" class="rounded-full bg-red-50 px-2 py-0.5 text-[10px] font-bold text-red-600 transition hover:bg-red-100">
                                                            Incorrect
                                                        </button>

                                                        <button type="button" data-question-mark="auto" title="Revert to the auto-grade" aria-label="Revert to the auto-grade" class="rounded-full p-1 text-[#767676] transition hover:bg-gray-200 hover:text-gray-600">
                                                            <svg xmlns="http://www.w3.org/2000/svg" class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                                                <path stroke-linecap="round" stroke-linejoin="round" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
                                                            </svg>
                                                        </button>

                                                    </div>

                                                <?php endif; ?>

                                            <?php else: ?>

                                                <div class="ml-auto flex items-center gap-2">

                                                    <span class="text-[10px] font-bold text-[#946200]">
                                                        <?= $response && !empty($response['graded_at']) ? 'Graded' : 'Needs your grade' ?>
                                                    </span>

                                                    <input
                                                        type="number"
                                                        min="0"
                                                        step="0.5"
                                                        max="<?= esc($question['points']) ?>"
                                                        value="<?= $response ? esc($awardedLabel) : '' ?>"
                                                        placeholder="0"
                                                        <?= $canEditQuestion ? '' : 'disabled' ?>
                                                        data-question-points-input
                                                        class="w-16 rounded-lg border border-gray-200 px-2 py-1 text-center text-sm font-bold text-brand outline-none focus:border-brand disabled:bg-gray-100 disabled:text-gray-400">

                                                    <span class="text-[10px] text-gray-500">/ <?= esc((int) $question['points']) ?> pts</span>

                                                </div>

                                            <?php endif; ?>

                                        </div>

                                        <p class="pb-3 text-sm font-medium text-[#1A1C1E]">
                                            <?= esc($question['question_text']) ?>
                                        </p>

                                        <?php if ($question['question_type'] === 'Multiple Choice'): ?>

                                            <?php $isMultipleAnswer = !empty($question['allow_multiple_answers']); ?>

                                            <div class="flex flex-col gap-1.5">

                                                <?php foreach (($question['choices'] ?? []) as $choice): ?>

                                                    <?php
                                                        $isSelected = $isMultipleAnswer
                                                            ? ($response && in_array((int) $choice['id'], array_map('intval', $response['selected_choice_ids'] ?? []), true))
                                                            : ($response && (int) $response['selected_choice_id'] === (int) $choice['id']);
                                                        $isCorrect = !empty($choice['is_correct']);
                                                    ?>

                                                    <div class="flex items-center gap-2 rounded-lg border px-3 py-2 text-sm
                                                        <?= $isCorrect ? 'border-emerald-300 bg-emerald-50 text-emerald-700' : ($isSelected ? 'border-red-300 bg-red-50 text-red-600' : 'border-gray-200 bg-white text-gray-600') ?>">

                                                        <?= esc($choice['choice_text']) ?>

                                                        <?php if ($isSelected): ?>
                                                            <span class="ml-auto text-[10px] font-bold uppercase">Selected</span>
                                                        <?php endif; ?>

                                                    </div>

                                                <?php endforeach; ?>

                                            </div>

                                        <?php elseif ($question['question_type'] === 'Short Answer'): ?>

                                            <div class="flex flex-col gap-2">

                                                <div class="rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm text-gray-700">
                                                    <?php if ($response && trim((string) $response['response_text']) !== ''): ?>
                                                        <?= esc($response['response_text']) ?>
                                                    <?php else: ?>
                                                        <span class="text-[#767676]">No response submitted.</span>
                                                    <?php endif; ?>
                                                </div>

                                                <?php if (!empty($question['accepted_answers'])): ?>

                                                    <span class="text-xs text-gray-500">
                                                        Accepted:
                                                        <span class="font-semibold text-emerald-700"><?= esc(implode(' · ', $question['accepted_answers'])) ?></span>
                                                        <?= !empty($question['case_sensitive']) ? ' · case sensitive' : '' ?>
                                                    </span>

                                                <?php else: ?>

                                                    <span class="text-xs text-[#767676]">No answer key — grade this one yourself.</span>

                                                <?php endif; ?>

                                            </div>

                                        <?php elseif ($question['question_type'] === 'File Upload'): ?>

                                            <div class="rounded-lg border border-dashed border-gray-200 bg-white px-3 py-2 text-sm text-gray-500">
                                                <?= $response && $response['uploaded_file_id'] ? 'File submitted' : 'No file submitted' ?>
                                            </div>

                                        <?php else: ?>

                                            <div class="rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm text-gray-700">
                                                <?= $response && $response['response_text'] !== null && $response['response_text'] !== ''
                                                    ? nl2br(esc($response['response_text']))
                                                    : '<span class="text-[#767676]">No response submitted.</span>' ?>
                                            </div>

                                        <?php endif; ?>

                                    </div>

                                <?php endforeach; ?>

                            </div>

                        <?php elseif (!empty($isAssignment)): ?>

                            <?= view('pages/courses/features/assessments/partials/submitted-work', ['attempt' => $submission]) ?>

                        <?php else: ?>

                            <div class="rounded-xl border border-dashed border-gray-200 bg-[#F9F9FC] px-4 py-6 text-center text-xs text-[#767676]">
                                No submission content available to preview for this assessment type.
                            </div>

                        <?php endif; ?>

                    </div>

                <?php endif; ?>

            </div>

            <div
                data-expandable-grade-work-actions-panel
                data-submission-id="<?= esc($submission['id'] ?? '') ?>"
                data-submission-status="<?= esc($submissionStatus ?? '') ?>"
                data-panel-state="<?= esc($panelState) ?>"
                data-total-points="<?= esc($totalPoints) ?>"
                data-has-rubric="<?= $currentRubric ? '1' : '0' ?>"
                class="flex flex-col rounded-2xl border border-gray-100 p-5">

                <div class="flex items-center justify-between gap-3">

                    <span
                        data-grade-work-status-label
                        class="rounded-full px-3 py-1 text-xs font-bold <?= $statusMeta['class'] ?>">
                        <?= esc($statusMeta['label']) ?>
                    </span>

                    <span
                        data-grade-work-score-preview
                        class="font-bold text-brand">
                        <?= $existingScore !== null ? esc((int) $existingScore) . '/' . esc((int) $totalPoints) : 'No Score Yet' ?>
                    </span>

                    <?php if ($gradingViewOnly): ?>

                        <!-- Grading taken away by the institution: the work and its grade, no controls -->

                    <?php elseif ($panelState === 'locked'): ?>

                        <!-- Graded: read-only; changing the grade needs a confirmation -->
                        <button
                            type="button"
                            data-regrade-open
                            title="Change this grade" aria-label="Change this grade"
                            class="inline-flex items-center gap-1.5 rounded-xl border border-gray-200 px-3 py-2 text-sm font-semibold text-gray-600 transition hover:border-brand hover:text-brand">

                            <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5M16.5 3.5a2.121 2.121 0 113 3L12 14l-4 1 1-4 7.5-7.5z" />
                            </svg>

                            Edit

                        </button>

                    <?php elseif ($panelState === 'disabled'): ?>

                        <button
                            type="button"
                            disabled
                            class="inline-flex cursor-not-allowed items-center gap-1 rounded-xl bg-gray-100 px-4 py-2 text-sm font-semibold text-[#767676]">
                            Grade Work
                        </button>

                    <?php else: ?>

                        <button
                            type="button"
                            data-expand-grade-work-actions
                            data-expanded="<?= $panelState === 'editing' ? '1' : '0' ?>"
                            class="inline-flex items-center gap-1.5 rounded-xl bg-brand px-4 py-2 text-sm font-semibold text-white transition hover:bg-brand-dark">

                            <span data-expand-grade-work-label>Grade Work</span>

                            <svg data-expand-grade-work-icon xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 transition-transform <?= $panelState === 'editing' ? 'rotate-180' : '' ?>" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7" />
                            </svg>

                        </button>

                    <?php endif; ?>

                </div>

                <div
                    data-expanded-grade-work-actions
                    class="<?= in_array($panelState, ['editing', 'locked'], true) ? 'flex' : 'hidden' ?> mt-5 flex-col gap-5">

                    <?php if (!$isLocked && !empty($autoSummary) && $autoSummary['manual'] > 0 && $autoSummary['possible'] > 0): ?>

                        <!-- Auto-graded part already counted in the score below -->
                        <div class="rounded-xl border border-[#FFE3A3] bg-[#FFF8E6] px-4 py-3 text-sm text-[#946200]">
                            Auto-graded so far: <strong><?= esc(round($autoSummary['earned'], 2)) ?> of <?= esc(round($autoSummary['possible'], 2)) ?> pts</strong>.
                            <?= esc($autoSummary['manual']) ?> <?= $autoSummary['manual'] === 1 ? 'question needs' : 'questions need' ?> your grade — add
                            <?= $autoSummary['manual'] === 1 ? 'its' : 'their' ?> points to the total.
                        </div>

                    <?php endif; ?>

                    <?php if (!$isAssignment): ?>

                        <!-- Quizzes are graded per-question above; this is just the sum. -->
                        <div class="flex flex-col gap-2 rounded-xl bg-[#F2F2F2] p-4">

                            <span class="text-sm font-bold text-[#1A1C1E]">Grade</span>

                            <div class="flex items-center gap-2">
                                <span class="font-bold text-lg text-brand" data-grade-work-quiz-total>
                                    <?= esc(rtrim(rtrim(number_format((float) ($submission['score'] ?? 0), 2), '0'), '.')) ?>
                                </span>
                                <span class="text-sm text-[#646464]">of <?= esc((int) $totalPoints) ?> pts — from the question grades above</span>
                            </div>

                        </div>

                    <?php else: ?>

                    <!-- Grade -->
                    <div class="flex flex-col gap-3">

                        <span class="text-sm font-bold text-[#1A1C1E]">
                            Grade<?= $isLocked ? ' · ' . ($showRubricMode ? 'Rubric' : 'Default') : '' ?>
                        </span>

                        <?php if (!$isLocked): ?>

                            <div
                                data-grade-work-type-toggle-container
                                class="inline-flex w-fit rounded-xl bg-gray-100 p-1">

                                <button
                                    type="button"
                                    data-default-grade-work-toggle
                                    data-mode="Default"
                                    class="rounded-lg px-4 py-1.5 text-sm font-semibold transition">

                                    Default

                                </button>

                                <button
                                    type="button"
                                    data-with-rubric-grade-work-toggle
                                    data-mode="Rubric"
                                    <?= $currentRubric ? '' : 'disabled title="No rubric linked to this assessment"' ?>
                                    class="rounded-lg px-4 py-1.5 text-sm font-semibold transition <?= $currentRubric ? '' : 'cursor-not-allowed opacity-40' ?>">

                                    Rubric Grading

                                </button>

                            </div>

                        <?php endif; ?>

                        <div class="flex flex-col gap-3">

                            <!-- Default grading -->
                            <div
                                data-default-grading-panel
                                data-active="<?= $showRubricMode ? '0' : '1' ?>"
                                class="<?= $showRubricMode ? 'hidden' : 'flex' ?> flex-col gap-3 rounded-xl bg-[#F2F2F2] p-4">

                                <div class="flex items-center gap-2">

                                    <input
                                        type="number"
                                        min="0"
                                        step="0.5"
                                        max="<?= esc($totalPoints) ?>"
                                        value="<?= $existingGradingMode === 'Default' && $existingScore !== null ? esc($existingScore) : '' ?>"
                                        placeholder="0"
                                        <?= $isLocked ? 'disabled' : '' ?>
                                        data-grade-work-default-grading-points
                                        class="w-20 rounded-lg border-0 border-b-2 border-b-[#5B5B5B] bg-transparent px-1 py-1 text-center font-bold text-lg text-brand outline-none disabled:border-b-transparent">

                                    <span class="text-sm text-[#646464]">
                                        of
                                    </span>

                                    <span class="font-bold text-sm text-[#646464]">
                                        <?= esc((int) $totalPoints) ?> pts
                                    </span>

                                </div>

                                <?php if (!$isLocked): ?>

                                    <input
                                        type="range"
                                        min="0"
                                        max="<?= esc($totalPoints) ?>"
                                        step="0.5"
                                        value="<?= $existingGradingMode === 'Default' && $existingScore !== null ? esc($existingScore) : 0 ?>"
                                        data-grade-work-default-grading-slider
                                        class="w-full accent-brand">

                                <?php endif; ?>

                            </div>

                            <!-- Rubric grading -->
                            <div
                                data-rubric-grading-panel
                                data-active="<?= $showRubricMode ? '1' : '0' ?>"
                                class="<?= $showRubricMode ? 'flex' : 'hidden' ?> flex-row gap-3">

                                <?php foreach (($currentRubric['criteria'] ?? []) as $criterion): ?>

                                    <?php
                                        $criterionValue = $existingGradingMode === 'Rubric'
                                            ? ($existingRubricScores[$criterion['id']] ?? 0)
                                            : 0;
                                    ?>

                                    <div
                                        data-rubric-criterion
                                        data-criterion-id="<?= esc($criterion['id']) ?>"
                                        data-max-points="<?= esc($criterion['max_points']) ?>"
                                        class="flex flex-col gap-2 rounded-xl bg-[#F2F2F2] p-4">

                                        <div class="flex items-center gap-2">

                                            <input
                                                type="number"
                                                min="0"
                                                step="0.5"
                                                max="<?= esc($criterion['max_points']) ?>"
                                                value="<?= esc($criterionValue) ?>"
                                                <?= $isLocked ? 'disabled' : '' ?>
                                                data-grade-work-rubric-criteria-points
                                                class="w-16 rounded-lg border-0 border-b-2 border-b-[#5B5B5B] bg-transparent px-1 py-1 text-center font-bold text-md text-brand outline-none disabled:border-b-transparent">

                                            <span class="text-sm text-[#646464]">
                                                of
                                            </span>

                                            <span class="font-bold text-sm text-[#646464]">
                                                <?= esc((int) $criterion['max_points']) ?> pts
                                            </span>

                                        </div>

                                        <span class="font-bold text-sm text-[#646464]">
                                            <?= esc($criterion['criteria_title']) ?>
                                        </span>

                                        <?php if (!empty($criterion['description'])): ?>

                                            <span class="text-xs text-[#767676]">
                                                <?= esc($criterion['description']) ?>
                                            </span>

                                        <?php endif; ?>

                                        <?php if (!$isLocked): ?>

                                            <input
                                                type="range"
                                                min="0"
                                                max="<?= esc($criterion['max_points']) ?>"
                                                step="0.5"
                                                value="<?= esc($criterionValue) ?>"
                                                data-grade-work-rubric-criteria-slider
                                                class="w-full accent-brand">

                                        <?php endif; ?>

                                    </div>

                                <?php endforeach; ?>

                            </div>

                        </div>

                    </div>

                    <?php endif; ?>

                    <!-- Feedback: prior messages already show in the sidebar
                         thread (score-panel.php); this just adds a new one
                         alongside the grade being submitted right now. -->
                    <?php if (!$isLocked): ?>

                        <div class="flex flex-col gap-2">

                            <span class="text-sm font-bold text-[#1A1C1E]">
                                Add feedback (optional)
                            </span>

                            <textarea
                                rows="3"
                                data-grade-work-feedback
                                placeholder="Leave feedback for the student..."
                                class="w-full rounded-xl border border-gray-200 p-3 text-sm outline-none transition focus:border-brand"></textarea>

                        </div>

                    <?php endif; ?>

                    <?php if (!$isLocked): ?>

                        <button
                            type="button"
                            data-grade-work-submit-grade
                            class="self-end rounded-xl bg-brand px-6 py-2.5 text-sm font-semibold text-white transition hover:bg-brand-dark">

                            Submit Grade

                        </button>

                    <?php endif; ?>

                </div>

            </div>

        </div>

        <?= $this->include('pages/courses/features/assessments/partials/score-panel') ?>

    </div>

</div>

<?= $this->include('pages/courses/features/assessments/partials/modals/regrade-confirm') ?>
