<?php

$totalPoints = (float) ($currentAssessment['total_points'] ?? 0);

$submissionStatus = $selectedStudent['submission_status'] ?? null;

$statusMeta = match ($submissionStatus) {
    'Submitted' => ['label' => 'Ready for Grading', 'class' => 'bg-[#0040A6]/10 text-[#0040A6]', 'canExpand' => true],
    'Grading' => ['label' => 'Grading In Progress', 'class' => 'bg-[#FFF1D6] text-[#946200]', 'canExpand' => true],
    'Graded' => ['label' => 'Graded', 'class' => 'bg-emerald-100 text-emerald-700', 'canExpand' => true],
    'Returned' => ['label' => 'Returned', 'class' => 'bg-gray-100 text-gray-500', 'canExpand' => false],
    'Cancelled' => ['label' => 'Cancelled', 'class' => 'bg-red-50 text-red-500', 'canExpand' => false],
    'In Progress' => ['label' => 'Not Submitted Yet', 'class' => 'bg-gray-100 text-gray-400', 'canExpand' => false],
    default => ['label' => 'No Submission', 'class' => 'bg-gray-100 text-gray-400', 'canExpand' => false],
};

$existingScore = $submission['score'] ?? null;

$existingRubricScores = $submission['rubric_scores'] ?? [];

$existingGradingMode = $submission['grading_mode'] ?? 'Default';
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

                <span class="text-xl font-bold text-[#0040A6]">
                    Grade Work
                </span>

                <span class="text-gray-300">
                    ·
                </span>

                <span class="text-lg font-semibold text-[#5B5B5B]">
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

                <span class="text-xs font-bold uppercase tracking-wide text-gray-400">
                    Students
                </span>

                <div class="flex items-center gap-2">

                    <select
                        data-grade-work-status-filter
                        class="rounded-lg border border-gray-200 px-2 py-1 text-xs text-gray-600 outline-none focus:border-[#0040A6]">

                        <option value="">All</option>
                        <option value="Submitted">Submitted</option>
                        <option value="Grading">Grading</option>
                        <option value="Graded">Graded</option>
                        <option value="Returned">Returned</option>
                        <option value="In Progress">In Progress</option>
                        <option value="">No Submission</option>

                    </select>

                    <button
                        type="button"
                        data-sort-students
                        title="Sort by name"
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
                            'Submitted' => ['label' => 'Submitted', 'class' => 'bg-[#0040A6]/10 text-[#0040A6]'],
                            'Grading' => ['label' => 'Grading', 'class' => 'bg-[#FFF1D6] text-[#946200]'],
                            'Graded' => ['label' => 'Graded', 'class' => 'bg-emerald-100 text-emerald-700'],
                            'Returned' => ['label' => 'Returned', 'class' => 'bg-gray-100 text-gray-500'],
                            'Cancelled' => ['label' => 'Cancelled', 'class' => 'bg-red-50 text-red-500'],
                            'In Progress' => ['label' => 'In Progress', 'class' => 'bg-gray-100 text-gray-400'],
                            default => ['label' => 'No Submission', 'class' => 'bg-gray-100 text-gray-400'],
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
                        class="flex cursor-pointer items-center gap-3 rounded-xl border p-3 transition-colors <?= $isActive ? 'border-[#0040A6] bg-[#0040A6]/5' : 'border-gray-200 bg-white hover:border-[#0040A6]/40' ?>">

                        <div class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-[#0040A6]/10 text-xs font-bold text-[#0040A6]">
                            <?= esc(mb_substr($initials, 0, 2)) ?>
                        </div>

                        <div class="flex min-w-0 flex-1 flex-col">

                            <span class="truncate text-sm font-semibold text-[#1F2937]">
                                <?= esc($student['student_name']) ?>
                            </span>

                            <span class="rounded-full self-start px-2 py-0.5 text-[10px] font-bold <?= $rowMeta['class'] ?>">
                                <?= esc($rowMeta['label']) ?>
                            </span>

                        </div>

                        <?php if (!empty($student['is_graded'])): ?>

                            <span class="shrink-0 text-xs font-bold text-[#5B5B5B]">
                                <?= esc((int) $student['submission_score']) ?>/<?= esc((int) $totalPoints) ?>
                            </span>

                        <?php endif; ?>

                    </div>

                <?php endforeach; ?>

                <?php if (empty($students)): ?>

                    <div class="rounded-xl border border-dashed border-gray-200 p-4 text-center text-xs text-gray-400">
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
                        <span class="text-sm font-semibold text-[#5B5B5B]">Select a student</span>
                        <span class="text-xs text-[#939393]">Choose a student from the left to view their work.</span>
                    </div>

                <?php elseif (!$submission): ?>

                    <div class="flex h-full flex-col items-center justify-center gap-2 py-16 text-center">
                        <span class="text-sm font-semibold text-[#5B5B5B]">No submission yet</span>
                        <span class="text-xs text-[#939393]"><?= esc($selectedStudent['student_name']) ?> has not submitted this assessment.</span>
                    </div>

                <?php else: ?>

                    <div class="flex flex-col gap-4">

                        <div class="flex items-center justify-between border-b border-gray-100 pb-3">

                            <span class="font-bold text-[#1F2937]">
                                <?= esc($selectedStudent['student_name']) ?>'s Work
                            </span>

                            <span class="text-xs text-gray-400">
                                <?= $submission['submitted_at'] ? 'Submitted ' . esc(date('F j, Y g:i A', strtotime($submission['submitted_at']))) : 'Not submitted' ?>
                                <?= !empty($submission['is_late_submission']) ? ' · Late' : '' ?>
                                · Attempt <?= esc((int) ($submission['attempt_number'] ?? 1)) ?>
                            </span>

                        </div>

                        <?php if (!empty($questionResponses)): ?>

                            <div class="flex flex-col gap-4">

                                <?php foreach ($questionResponses as $index => $question): ?>

                                    <?php $response = $question['response'] ?? null; ?>

                                    <div class="rounded-xl border border-gray-100 bg-[#F9F9FC] p-4">

                                        <div class="flex items-center gap-2 pb-2">

                                            <span class="text-xs font-bold text-gray-400">Q<?= esc($index + 1) ?></span>

                                            <span class="rounded-full bg-gray-100 px-2 py-0.5 text-[10px] font-bold text-gray-600">
                                                <?= esc((int) $question['points']) ?> pts
                                            </span>

                                        </div>

                                        <p class="pb-3 text-sm font-medium text-[#1F2937]">
                                            <?= esc($question['question_text']) ?>
                                        </p>

                                        <?php if ($question['question_type'] === 'Multiple Choice'): ?>

                                            <div class="flex flex-col gap-1.5">

                                                <?php foreach (($question['choices'] ?? []) as $choice): ?>

                                                    <?php
                                                        $isSelected = $response && (int) $response['selected_choice_id'] === (int) $choice['id'];
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

                                        <?php elseif ($question['question_type'] === 'File Upload'): ?>

                                            <div class="rounded-lg border border-dashed border-gray-200 bg-white px-3 py-2 text-sm text-gray-500">
                                                <?= $response && $response['uploaded_file_id'] ? 'File submitted' : 'No file submitted' ?>
                                            </div>

                                        <?php else: ?>

                                            <div class="rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm text-gray-700">
                                                <?= $response && $response['response_text'] !== null && $response['response_text'] !== ''
                                                    ? nl2br(esc($response['response_text']))
                                                    : '<span class="text-gray-400">No response submitted.</span>' ?>
                                            </div>

                                        <?php endif; ?>

                                    </div>

                                <?php endforeach; ?>

                            </div>

                        <?php else: ?>

                            <div class="rounded-xl border border-dashed border-gray-200 bg-[#F9F9FC] px-4 py-6 text-center text-xs text-gray-400">
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
                data-total-points="<?= esc($totalPoints) ?>"
                data-has-rubric="<?= $currentRubric ? '1' : '0' ?>"
                class="flex flex-col rounded-2xl border border-gray-100 p-5">

                <div class="flex items-center justify-between">

                    <span
                        data-grade-work-status-label
                        class="rounded-full px-3 py-1 text-xs font-bold <?= $statusMeta['class'] ?>">
                        <?= esc($statusMeta['label']) ?>
                    </span>

                    <span
                        data-grade-work-score-preview
                        class="font-bold text-[#0040A6]">
                        <?= $existingScore !== null ? esc((int) $existingScore) . '/' . esc((int) $totalPoints) : 'No Score Yet' ?>
                    </span>

                    <?php if (!$submission || !$statusMeta['canExpand']): ?>

                        <button
                            type="button"
                            disabled
                            class="inline-flex cursor-not-allowed items-center gap-1 rounded-xl bg-gray-100 px-4 py-2 text-sm font-semibold text-gray-400">
                            Grade Work
                        </button>

                    <?php else: ?>

                        <button
                            type="button"
                            data-expand-grade-work-actions
                            data-expanded="0"
                            class="inline-flex items-center gap-1.5 rounded-xl bg-[#0040A6] px-4 py-2 text-sm font-semibold text-white transition hover:bg-[#002068]">

                            <span data-expand-grade-work-label>
                                <?= $submissionStatus === 'Graded' ? 'Edit Grade' : 'Grade Work' ?>
                            </span>

                            <svg data-expand-grade-work-icon xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 transition-transform" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7" />
                            </svg>

                        </button>

                    <?php endif; ?>

                </div>

                <div
                    data-expanded-grade-work-actions
                    class="hidden mt-5 flex-col gap-5">

                    <!-- Grade -->
                    <div class="flex flex-col gap-3">

                        <span class="text-sm font-bold text-[#1F2937]">
                            Grade
                        </span>

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

                        <div class="flex flex-col gap-3">

                            <!-- Default grading -->
                            <div
                                data-default-grading-panel
                                class="flex flex-col gap-3 rounded-xl bg-[#F2F2F2] p-4">

                                <div class="flex items-center gap-2">

                                    <input
                                        type="number"
                                        min="0"
                                        step="0.5"
                                        max="<?= esc($totalPoints) ?>"
                                        value="<?= $existingGradingMode === 'Default' && $existingScore !== null ? esc($existingScore) : '' ?>"
                                        placeholder="0"
                                        data-grade-work-default-grading-points
                                        class="w-20 rounded-lg border-0 border-b-2 border-b-[#5B5B5B] bg-transparent px-1 py-1 text-center font-bold text-lg text-[#0040A6] outline-none">

                                    <span class="text-sm text-[#5B5B5B]">
                                        of
                                    </span>

                                    <span class="font-bold text-sm text-[#5B5B5B]">
                                        <?= esc((int) $totalPoints) ?> pts
                                    </span>

                                </div>

                                <input
                                    type="range"
                                    min="0"
                                    max="<?= esc($totalPoints) ?>"
                                    step="0.5"
                                    value="<?= $existingGradingMode === 'Default' && $existingScore !== null ? esc($existingScore) : 0 ?>"
                                    data-grade-work-default-grading-slider
                                    class="w-full accent-[#0040A6]">

                            </div>

                            <!-- Rubric grading -->
                            <div
                                data-rubric-grading-panel
                                class="hidden flex-row gap-3">

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
                                                data-grade-work-rubric-criteria-points
                                                class="w-16 rounded-lg border-0 border-b-2 border-b-[#5B5B5B] bg-transparent px-1 py-1 text-center font-bold text-md text-[#0040A6] outline-none">

                                            <span class="text-sm text-[#5B5B5B]">
                                                of
                                            </span>

                                            <span class="font-bold text-sm text-[#5B5B5B]">
                                                <?= esc((int) $criterion['max_points']) ?> pts
                                            </span>

                                        </div>

                                        <span class="font-bold text-sm text-[#5B5B5B]">
                                            <?= esc($criterion['criteria_title']) ?>
                                        </span>

                                        <?php if (!empty($criterion['description'])): ?>

                                            <span class="text-xs text-[#878787]">
                                                <?= esc($criterion['description']) ?>
                                            </span>

                                        <?php endif; ?>

                                        <input
                                            type="range"
                                            min="0"
                                            max="<?= esc($criterion['max_points']) ?>"
                                            step="0.5"
                                            value="<?= esc($criterionValue) ?>"
                                            data-grade-work-rubric-criteria-slider
                                            class="w-full accent-[#0040A6]">

                                    </div>

                                <?php endforeach; ?>

                            </div>

                        </div>

                    </div>

                    <!-- Feedback -->
                    <div class="flex flex-col gap-2">

                        <span class="text-sm font-bold text-[#1F2937]">
                            Feedback
                        </span>

                        <textarea
                            rows="3"
                            data-grade-work-feedback
                            placeholder="Leave feedback for the student..."
                            class="w-full rounded-xl border border-gray-200 p-3 text-sm outline-none transition focus:border-[#0040A6]"><?= esc($submission['feedback'] ?? '') ?></textarea>

                    </div>

                    <button
                        type="button"
                        data-grade-work-submit-grade
                        class="self-end rounded-xl bg-[#0040A6] px-6 py-2.5 text-sm font-semibold text-white transition hover:bg-[#002068]">

                        Submit Grade

                    </button>

                </div>

            </div>

        </div>

        <?= $this->include('pages/courses/features/assessments/partials/score-panel') ?>

    </div>

</div>
