<?php

// The learning item stacks this panel under the item below xl (detail-panel passes these two).
$panelClass ??= 'w-1/5';
$scoreBoxClass ??= 'aspect-square';

$userRole = \App\Services\Courses\CourseViewer::seesInstructorView($user) ? 'instructor' : esc($user->getGroups()[0] ?? '');

$isInstructor = $userRole === 'instructor';

$totalPoints = (float) ($currentAssessment['total_points'] ?? 0);

$submission ??= null;

/*
|--------------------------------------------------------------------------
| Instructor viewing without a selected student (e.g. view.php via the
| eye button) has no student context to show a score for — skip the panel.
|--------------------------------------------------------------------------
*/

$hasStudentContext = $isInstructor
    ? !empty($selectedStudent)
    : true;

$scoreHeading = $isInstructor
    ? esc($selectedStudent['student_name'] ?? 'Student') . "'s Score"
    : 'Your Score';

$feedbackHeading = $isInstructor
    ? esc($selectedStudent['student_name'] ?? 'Student') . "'s Feedback"
    : 'Feedback';

/*
|--------------------------------------------------------------------------
| The score shown is the attempt that counts (AssessmentResultService):
| the best quiz attempt, or the latest graded assignment submission. When
| $scoreResult isn't given, fall back to $submission as before.
|--------------------------------------------------------------------------
*/

$scoreResult ??= null;

$scored = $scoreResult !== null ? $scoreResult['counted'] : $submission;

$isGraded = !empty($scored['is_graded']);

/*
|--------------------------------------------------------------------------
| Feedback thread — always about the attempt currently on screen
| ($submission), not necessarily the counted one $scored refers to (an
| instructor can be viewing a non-counted attempt's tab specifically to
| leave feedback about it).
|--------------------------------------------------------------------------
*/

$thread = $submission['feedback_thread'] ?? [];

$submissionGraded = !empty($submission['is_graded']);

$hasInstructorMessage = array_filter($thread, static fn ($entry) => $entry['author_role'] === 'instructor') !== [];

// A student only sees the thread once graded; the instructor always can.
$canViewThread = $isInstructor || $submissionGraded;

// The instructor can always add a message once a submission exists; a
// student can reply once the instructor has started the thread.
// The instructor's feedback is part of grading, which their institution can take away (grading.manage).
$canCompose = !empty($submission) && ($isInstructor ? ! \App\Services\Courses\CourseAbilities::viewOnly(auth()->user(), 'grading') : ($submissionGraded && $hasInstructorMessage));

$scoreCaption = null;

// A group member's own breakdown (AssessmentResultService::memberAttempts): only ever this student's row.
$memberScore = $isGraded ? ($scored['member_score'] ?? null) : null;

$points = static fn ($value): string => rtrim(rtrim(number_format((float) $value, 2), '0'), '.');

$groupRubric = [];

if ($memberScore !== null) {

    $shared = is_string($scored['rubric_scores'] ?? null) ? (json_decode($scored['rubric_scores'], true) ?? []) : (array) ($scored['rubric_scores'] ?? []);

    foreach ($currentRubric['criteria'] ?? [] as $criterion) {

        $individual = ($criterion['scope'] ?? 'individual') === 'individual';
        $given = $individual ? ($memberScore['individual_rubric_scores'][$criterion['id']] ?? null) : ($shared[$criterion['id']] ?? null);

        if ($given !== null) {
            $groupRubric[] = ['title' => $criterion['criteria_title'] ?? '', 'scope' => $individual ? 'Individual' : 'Group', 'points' => $given, 'max' => $criterion['max_points'] ?? 0];
        }

    }

}

if ($isGraded && $scoreResult !== null) {

    $scoreCaption = $scoreResult['policy'] === 'highest'
        ? (count($scoreResult['finished']) > 1 ? 'Highest score · Attempt ' . (int) $scored['attempt_number'] : null)
        : ($scoreResult['resubmitted']
            ? ($isInstructor ? 'Previous submission — a resubmission is waiting' : 'For your previous submission — your resubmission is being reviewed')
            : null);

}
?>

<?php if ($hasStudentContext): ?>

    <div class="<?= $panelClass ?> flex flex-col gap-8">

        <!-- Score -->
        <div class="flex flex-col gap-3">

            <div class="flex items-center gap-3">
                <?= icon('score', 'h-5 w-5') ?>

                <span class="min-w-0 wrap-anywhere font-bold text-lg text-brand">
                    <?= $scoreHeading ?>
                </span>
            </div>

            <?php if ($isGraded): ?>

                <div class="w-full <?= $scoreBoxClass ?> p-10 flex flex-col justify-center items-center bg-[#F9F9FC] border-1 border-[#DBDBDB] rounded-2xl gap-2">

                    <?php if (!empty($scored['excused'])): ?>

                        <span class="font-bold text-2xl text-brand">Excused</span>

                        <span class="text-center text-xs text-[#646464]">This work does not count toward your grade.</span>

                    <?php else: ?>

                        <span class="font-bold text-4xl text-brand">
                            <?= esc((int) $scored['score']) ?>/<?= esc((int) $totalPoints) ?>
                        </span>

                        <span class="text-sm font-semibold text-[#646464]">
                            <?= esc((float) ($scored['percentage'] ?? 0)) ?>%
                        </span>

                    <?php endif; ?>

                    <?php if ($scoreCaption): ?>
                        <span class="text-center text-xs text-[#646464]"><?= esc($scoreCaption) ?></span>
                    <?php endif; ?>

                </div>

                <?php if ($memberScore !== null && empty($scored['excused'])): ?>

                    <!-- Group activity: this student's own parts of the grade -->
                    <dl class="flex flex-col gap-2 rounded-2xl border border-[#DBDBDB] bg-[#F9F9FC] p-4 text-sm text-[#646464]" data-group-result>

                        <?php if ($memberScore['group_points'] !== null): ?>
                            <div class="flex justify-between gap-3"><dt>Group part</dt><dd class="font-semibold text-brand"><?= esc($points($memberScore['group_points'])) ?></dd></div>
                        <?php endif; ?>

                        <?php if ($memberScore['individual_points'] !== null): ?>
                            <div class="flex justify-between gap-3"><dt>Your individual part</dt><dd class="font-semibold text-brand"><?= esc($points($memberScore['individual_points'])) ?></dd></div>
                        <?php endif; ?>

                        <?php foreach ($groupRubric as $line): ?>
                            <div class="flex justify-between gap-3 text-xs">
                                <dt class="min-w-0 wrap-anywhere"><?= esc($line['title']) ?> <span class="text-[#646464]">· <?= esc($line['scope']) ?></span></dt>
                                <dd><?= esc($points($line['points'])) ?>/<?= esc($points($line['max'])) ?></dd>
                            </div>
                        <?php endforeach; ?>

                        <?php if ((float) $memberScore['adjustment'] != 0.0): ?>
                            <div class="flex flex-wrap justify-between gap-x-3 gap-y-1">
                                <dt>Your adjustment</dt>
                                <dd class="font-semibold text-brand"><?= esc(((float) $memberScore['adjustment'] > 0 ? '+' : '') . $points($memberScore['adjustment'])) ?></dd>
                                <dd class="w-full text-xs whitespace-pre-line"><?= esc((string) $memberScore['adjustment_reason']) ?></dd>
                            </div>
                        <?php endif; ?>

                        <?php if (trim((string) $memberScore['private_note']) !== ''): ?>
                            <div class="flex flex-col gap-1">
                                <dt class="font-semibold">Note from your instructor</dt>
                                <dd class="text-xs whitespace-pre-line"><?= esc((string) $memberScore['private_note']) ?></dd>
                            </div>
                        <?php endif; ?>

                    </dl>

                <?php endif; ?>

            <?php elseif (!empty($scoreResult['partial'])): ?>

                <!-- Auto-graded part of a quiz; essays / uploads still being graded -->
                <div class="w-full <?= $scoreBoxClass ?> p-10 flex flex-col justify-center items-center bg-[#FFF8E6] border-1 border-[#FFE3A3] rounded-2xl gap-2">

                    <span class="font-bold text-4xl text-[#946200]">
                        <?= esc((int) $scoreResult['partial']['score']) ?>/<?= esc((int) $totalPoints) ?>
                    </span>

                    <span class="text-sm font-semibold text-[#946200]">
                        So far
                    </span>

                    <span class="text-xs text-center text-[#946200]">
                        <?= $isInstructor
                            ? 'Auto-graded part — the rest still needs grading.'
                            : 'The rest is still being graded.' ?>
                    </span>

                </div>

            <?php else: ?>

                <div class="w-full <?= $scoreBoxClass ?> p-10 flex flex-col justify-center items-center bg-[#F9F9FC] border-1 border-[#DBDBDB] rounded-2xl gap-2">

                    <?= icon('no-score', 'h-10 w-10') ?>

                    <span class="font-bold text-md text-[#646464]">
                        No Score Yet
                    </span>

                    <span class="text-xs text-[#646464] text-center">
                        <?= $isInstructor
                            ? 'A score will appear here once this student has been graded.'
                            : 'Your score will appear here once the instructor has given the grade.' ?>
                    </span>

                </div>

            <?php endif; ?>

        </div>

        <!-- Feedback thread -->
        <div class="flex flex-col gap-3" data-feedback-panel data-feedback-submission-id="<?= (int) ($submission['id'] ?? 0) ?>">

            <div class="flex items-center gap-3">
                <?= icon('feedbacks', 'h-5 w-5') ?>

                <span class="min-w-0 wrap-anywhere font-bold text-lg text-brand">
                    <?= $feedbackHeading ?>
                </span>
            </div>

            <?php if ($canViewThread && $thread !== []): ?>

                <div class="flex flex-col gap-2 max-h-72 overflow-y-auto pr-1" data-feedback-thread>

                    <?php foreach ($thread as $entry): ?>

                        <?php
                        $isOwnMessage = (int) $entry['author_id'] === (int) $user->id;
                        $authorLabel = $isOwnMessage ? 'You' : (trim((string) ($entry['author_name'] ?? '')) ?: ucfirst($entry['author_role']));
                        ?>

                        <div class="flex flex-col gap-1 rounded-2xl p-3 <?= $isOwnMessage ? 'bg-brand/10 items-end text-right' : 'bg-[#F9F9FC] border border-[#DBDBDB] items-start' ?>">

                            <span class="text-xs font-bold <?= $isOwnMessage ? 'text-brand' : 'text-[#646464]' ?>">
                                <?= esc($authorLabel) ?>
                            </span>

                            <p class="text-sm text-[#646464] whitespace-pre-line">
                                <?= nl2br(esc($entry['message'])) ?>
                            </p>

                            <span class="text-[10px] text-[#646464]">
                                <?= esc(date('M j, g:i A', strtotime($entry['created_at']))) ?>
                            </span>

                        </div>

                    <?php endforeach; ?>

                </div>

            <?php elseif (!$canViewThread): ?>

                <div class="w-full p-10 flex flex-col justify-center items-center bg-[#F9F9FC] border border-[#DBDBDB] rounded-2xl gap-2">

                    <?= icon('no-feedback', 'h-10 w-10') ?>

                    <span class="font-bold text-md text-[#646464]">
                        No Feedback Yet
                    </span>

                    <span class="text-xs text-[#646464] text-center">
                        Feedback will appear here once your work is graded.
                    </span>

                </div>

            <?php else: ?>

                <div class="w-full p-10 flex flex-col justify-center items-center bg-[#F9F9FC] border border-[#DBDBDB] rounded-2xl gap-2">

                    <?= icon('no-feedback', 'h-10 w-10') ?>

                    <span class="font-bold text-md text-[#646464]">
                        No Feedback Yet
                    </span>

                    <span class="text-xs text-[#646464] text-center">
                        <?= $isInstructor
                            ? 'Feedback you leave for this student will appear here.'
                            : 'Wait for your instructor\'s feedback, then you can reply here.' ?>
                    </span>

                </div>

            <?php endif; ?>

            <?php if ($canCompose): ?>

                <div class="flex flex-col gap-2" data-feedback-composer>

                    <textarea
                        rows="2"
                        data-feedback-message
                        placeholder="<?= $isInstructor ? 'Add a message...' : 'Reply to your instructor...' ?>"
                        class="w-full rounded-xl border border-gray-200 p-3 text-base outline-none transition focus:border-brand sm:text-sm"></textarea>

                    <button
                        type="button"
                        data-feedback-send
                        class="self-end rounded-xl bg-brand px-4 py-2 text-xs font-bold text-white transition hover:bg-brand-dark pointer-coarse:min-h-11 pointer-coarse:min-w-11">
                        Send
                    </button>

                    <span class="hidden text-xs text-red-600" data-feedback-error></span>

                </div>

            <?php endif; ?>

        </div>

    </div>

<?php endif; ?>
