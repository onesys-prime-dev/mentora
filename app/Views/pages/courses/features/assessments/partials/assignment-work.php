<?php

/*
|--------------------------------------------------------------------------
| Assignment "Your Work" + Submission History.
| Shared by the Assessments tab (assessment-types/views/assignment.php) and
| the Modules learning item (modules/learning-items/views/assignment.php).
| Behaviour lives in resources/js/shared/assignment/submission.js.
|--------------------------------------------------------------------------
*/

$isInstructor = \App\Services\Courses\CourseViewer::seesInstructorView($user);

$assignmentState ??= null;

$allowedTypes = $assignmentState['allowed'] ?? [
    'file' => (bool) ($currentSettings['allow_file_upload'] ?? false),
    'text' => (bool) ($currentSettings['allow_text_entry'] ?? false),
    'link' => (bool) ($currentSettings['allow_link_entry'] ?? false),
];

$typeLabels = [
    'file' => 'File Upload',
    'text' => 'Text Entry',
    'link' => 'Link',
];

$enabledTypes = array_keys(array_filter($allowedTypes));

$attempts = $assignmentState['attempts'] ?? [];

$latestAttempt = $assignmentState['latest'] ?? null;

$attemptsUsed = (int) ($assignmentState['attemptsUsed'] ?? 0);

$attemptLimit = (int) ($assignmentState['attemptLimit'] ?? max(1, (int) ($currentAssessment['attempt_limit'] ?? 1)));

$canSubmit = !$isInstructor && !empty($assignmentState['canSubmit']);

$attemptsLeft = max(0, $attemptLimit - $attemptsUsed);

$totalPoints = (int) ($currentAssessment['total_points'] ?? 0);

$statusMeta = static fn (?string $status) => match ($status) {
    'Submitted' => ['label' => 'Submitted', 'class' => 'bg-brand/10 text-brand'],
    'Grading' => ['label' => 'Being Graded', 'class' => 'bg-[#FFF1D6] text-[#946200]'],
    'Graded' => ['label' => 'Graded', 'class' => 'bg-[#E8F5EC] text-[#2E7D4F]'],
    'Returned' => ['label' => 'Returned', 'class' => 'bg-gray-100 text-gray-500'],
    'Cancelled' => ['label' => 'Cancelled', 'class' => 'bg-red-50 text-red-500'],
    default => ['label' => 'Not Submitted', 'class' => 'bg-gray-100 text-[#767676]'],
};

$formatDate = static fn (?string $datetime) => $datetime ? date('M j, Y g:i A', strtotime($datetime)) : '';
?>

<!-- Your Work -->
<div class="flex flex-col gap-3">

    <div class="flex items-center justify-between gap-3">

        <div class="flex items-center gap-3">
            <?= icon('work', 'h-5 w-5') ?>

            <span class="font-bold text-lg text-brand">
                Your Work
            </span>
        </div>

        <?php if ($latestAttempt): ?>

            <?php $latestMeta = $statusMeta($latestAttempt['status']); ?>

            <div class="flex items-center gap-2 text-xs text-gray-500">

                <span class="rounded-full px-2.5 py-1 font-bold <?= $latestMeta['class'] ?>">
                    <?= esc($latestMeta['label']) ?>
                </span>

                <span>
                    <?= esc($formatDate($latestAttempt['submitted_at'])) ?>
                    <?= !empty($latestAttempt['is_late_submission']) ? ' · <span class="font-semibold text-red-500">Late</span>' : '' ?>
                    · Attempt <?= esc($attemptsUsed) ?> of <?= esc($attemptLimit) ?>
                </span>

            </div>

        <?php endif; ?>

    </div>

    <?php if ($isInstructor): ?>

        <div class="flex flex-col items-center justify-center gap-3 rounded-2xl border border-dashed border-[#DBDBDB] bg-[#F9F9FC] p-10 text-center">

            <span class="text-sm font-semibold text-[#646464]">
                Students submit their work here
            </span>

            <?php if ($enabledTypes): ?>

                <div class="flex flex-wrap items-center justify-center gap-2">
                    <?php foreach ($enabledTypes as $type): ?>
                        <span class="rounded-full bg-white px-3 py-1 text-xs font-semibold text-brand ring-1 ring-brand/20">
                            <?= esc($typeLabels[$type]) ?>
                        </span>
                    <?php endforeach; ?>
                </div>

                <span class="text-xs text-[#767676]">
                    Up to <?= esc($attemptLimit) ?> submission<?= $attemptLimit === 1 ? '' : 's' ?> per student.
                </span>

            <?php else: ?>

                <span class="text-xs text-[#767676]">
                    No submission method is turned on yet — students can't submit until you allow file upload, text entry, or a link.
                </span>

            <?php endif; ?>

        </div>

    <?php elseif (!$canSubmit): ?>

        <div class="flex flex-col items-center justify-center gap-2 rounded-2xl border border-[#DBDBDB] bg-[#F9F9FC] p-8 text-center">

            <span class="text-sm font-semibold text-[#646464]">
                <?= esc($assignmentState['reason'] ?? "Submissions aren't available.") ?>
            </span>

            <?php if ($latestAttempt): ?>
                <span class="text-xs text-[#767676]">
                    Your latest submission is shown in the history below.
                </span>
            <?php endif; ?>

        </div>

    <?php else: ?>

        <form
            data-assignment-submission-form
            data-assessment-id="<?= esc($currentAssessment['id']) ?>"
            enctype="multipart/form-data"
            class="group/work-tabs flex flex-col"
            novalidate>

            <div class="flex w-fit overflow-hidden rounded-t-xl border-x border-t border-[#DBDBDB]">

                <?php foreach ($enabledTypes as $index => $type): ?>

                    <label
                        class="cursor-pointer bg-[#F2F2F4] px-4 py-2 text-sm font-semibold text-[#8A8A8A] transition-colors hover:text-brand
                            <?= $index < count($enabledTypes) - 1 ? 'border-r border-[#DBDBDB]' : '' ?>
                            group-has-[#assignment-work-tab-<?= $type ?>:checked]/work-tabs:bg-white
                            group-has-[#assignment-work-tab-<?= $type ?>:checked]/work-tabs:text-brand">

                        <input
                            type="radio"
                            name="work-tab"
                            id="assignment-work-tab-<?= $type ?>"
                            class="hidden"
                            <?= $index === 0 ? 'checked' : '' ?>>

                        <?= esc($typeLabels[$type]) ?>
                    </label>

                <?php endforeach; ?>

            </div>

            <?php if ($allowedTypes['file']): ?>

                <label
                    data-assignment-dropzone
                    class="hidden min-h-60 w-full cursor-pointer flex-col items-center justify-center gap-2 rounded-b-2xl rounded-r-2xl border border-[#DBDBDB] bg-white p-10 text-center transition-colors
                        hover:bg-[#F9F9FC]
                        data-dragging:border-brand data-dragging:bg-brand/5
                        group-has-[#assignment-work-tab-file:checked]/work-tabs:flex">

                    <input
                        type="file"
                        name="file"
                        class="hidden"
                        data-assignment-file-input>

                    <?= icon('work', 'h-10 w-10 pointer-events-none opacity-40') ?>

                    <span
                        data-assignment-file-name
                        class="pointer-events-none text-sm font-semibold text-[#646464]">
                        Drag and drop your file here
                    </span>

                    <span class="pointer-events-none text-xs text-[#767676]">
                        or click to browse · up to 20 MB
                    </span>

                </label>

            <?php endif; ?>

            <?php if ($allowedTypes['text']): ?>

                <div
                    class="hidden min-h-60 w-full flex-col rounded-b-2xl rounded-r-2xl border border-[#DBDBDB] bg-white p-5
                        group-has-[#assignment-work-tab-text:checked]/work-tabs:flex">

                    <textarea
                        name="submission_text"
                        rows="8"
                        class="min-h-52 w-full flex-1 resize-y border-0 text-sm text-[#646464] outline-none"
                        placeholder="Type your response here..."></textarea>

                </div>

            <?php endif; ?>

            <?php if ($allowedTypes['link']): ?>

                <div
                    class="hidden min-h-60 w-full flex-col items-center justify-center gap-2 rounded-b-2xl rounded-r-2xl border border-[#DBDBDB] bg-white p-10
                        group-has-[#assignment-work-tab-link:checked]/work-tabs:flex">

                    <?= icon('work', 'h-10 w-10 opacity-40') ?>

                    <input
                        type="url"
                        name="submission_link"
                        placeholder="https://..."
                        class="w-full max-w-md rounded-xl border border-[#DBDBDB] px-4 py-3 text-center text-sm text-[#646464] outline-none transition focus:border-brand">

                    <span class="text-xs text-[#767676]">
                        Paste a link to your work (e.g. Google Docs, GitHub).
                    </span>

                </div>

            <?php endif; ?>

            <div class="mt-3 flex items-center justify-between gap-4">

                <span class="text-xs text-[#767676]">
                    <?php if (count($enabledTypes) > 1): ?>
                        You can fill in more than one tab — everything is sent together.
                    <?php endif; ?>
                    <?= esc($attemptsLeft) ?> submission<?= $attemptsLeft === 1 ? '' : 's' ?> left.
                </span>

                <div class="flex items-center gap-3">

                    <span
                        data-assignment-submission-error
                        class="hidden text-xs font-semibold text-red-500"></span>

                    <button
                        type="submit"
                        data-assignment-submit
                        class="inline-flex items-center gap-2 rounded-xl bg-brand px-6 py-2.5 text-sm font-semibold text-white shadow-sm transition-colors hover:bg-brand-dark disabled:cursor-not-allowed disabled:opacity-60">
                        <?= $attemptsUsed > 0 ? 'Resubmit' : 'Submit' ?>
                    </button>

                </div>

            </div>

        </form>

    <?php endif; ?>

</div>

<?php if (!$isInstructor): ?>

    <!-- Submission History -->
    <?= view('pages/courses/features/assessments/partials/attempt-history', [
        'historyAttempts' => $attempts,
        'historyType' => 'assignment',
        'totalPoints' => $totalPoints,
        'countedAttemptId' => $assignmentState['counted']['id'] ?? null,
    ]) ?>

<?php endif; ?>
