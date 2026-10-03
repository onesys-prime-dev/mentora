<?php

/*
|--------------------------------------------------------------------------
| One assessment in the list. Students see their own status (Due in 2 days,
| Submitted, Graded 42/50, Locked…); instructors see the assessment's
| status, section submission counts and their View / Edit actions.
|
| data-filter-tags drives the list's filter select; data-title its search.
| Clicking the card opens the view (students) or Grade Work (instructors).
|--------------------------------------------------------------------------
*/

$a = $assessment;

$isInstructor ??= false;

$totalPoints = (int) ($a['total_points'] ?? 0);

$meta = array_filter([
    ucfirst((string) $a['assessment_type']),
    $a['due_ts'] !== null ? 'Due ' . date('M j, Y g:i A', $a['due_ts']) : 'No due date',
    $a['is_quiz'] ? (int) $a['question_count'] . ' ' . ((int) $a['question_count'] === 1 ? 'Question' : 'Questions') : null,
    $totalPoints . ' Points',
]);

$chipClasses = static fn (string $tone) => match ($tone) {
    'info' => 'bg-[#0040A6]/10 text-[#0040A6]',
    'success' => 'bg-[#E8F5EC] text-[#2E7D4F]',
    'warning' => 'bg-[#FFF1D6] text-[#946200]',
    'danger' => 'bg-red-50 text-red-600',
    'muted' => 'bg-gray-100 text-gray-500',
    default => 'bg-[#E0E5F2] text-[#002068]',
};

if ($isInstructor) {

    $filterTags = strtolower($a['status']);

} else {

    $student = $a['student'];

    $filterTags = implode(' ', array_filter([
        $student['actionable'] ? 'todo' : null,
        in_array($student['key'], ['submitted', 'grading', 'resubmitted', 'partially_graded'], true) ? 'submitted' : null,
        $student['key'] === 'graded' ? 'graded' : null,
        in_array($student['key'], ['overdue', 'missed'], true) ? 'overdue' : null,
    ]));

}
?>

<div
    class="group flex cursor-pointer items-center justify-between gap-6 rounded-2xl border border-[#A9A9A9]/50 px-8 py-6 outline-none transition-all duration-200
        hover:border-[#0040A6]/50 hover:bg-[#0040A6]/5 hover:shadow-sm
        focus-visible:border-[#0040A6] focus-visible:ring-2 focus-visible:ring-[#0040A6]/20"
    role="link"
    tabindex="0"
    data-assessment-card
    data-assessment="<?= esc($a['id']) ?>"
    data-title="<?= esc(mb_strtolower($a['title'])) ?>"
    data-filter-tags="<?= esc($filterTags) ?>">

    <div class="flex min-w-0 items-center gap-5">

        <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full border-3 border-[#0040A6]">
            <?= learning_item_icon($a['assessment_type'], 'h-5 w-5 text-[#0040A6]') ?>
        </div>

        <div class="flex min-w-0 flex-col">

            <div class="flex flex-wrap items-center gap-2">

                <span class="truncate text-lg font-bold text-[#646464]">
                    <?= esc($a['title']) ?>
                </span>

                <?php if ($isInstructor): ?>

                    <?php
                        $statusTone = match ($a['status']) {
                            'Published' => 'info',
                            'Closed' => 'warning',
                            default => 'neutral',
                        };
                    ?>

                    <?= view('components/status-badge', ['tone' => $statusTone, 'label' => $a['status']], ['saveData' => false]) ?>

                    <?php if ($a['section']['toGrade'] > 0): ?>
                        <?= view('components/status-badge', ['tone' => 'warning', 'icon' => 'tasks', 'label' => $a['section']['toGrade'] . ' to grade'], ['saveData' => false]) ?>
                    <?php endif; ?>

                <?php else: ?>

                    <span class="rounded-full px-3 py-1 text-xs font-black <?= $chipClasses($student['tone']) ?>">
                        <?= esc($student['label']) ?>
                    </span>

                <?php endif; ?>

            </div>

            <span class="text-sm text-[#767676]">
                <?= esc(implode(' | ', $meta)) ?>
            </span>

            <?php if (!$isInstructor && !empty($student['lock'])): ?>
                <span class="mt-0.5 text-xs text-[#767676]"><?= esc($student['lock']['message']) ?></span>
            <?php endif; ?>

        </div>

    </div>

    <div class="flex shrink-0 items-center gap-5">

        <?php if ($isInstructor): ?>

            <?php if ($a['status'] !== 'Draft'): ?>
                <span class="text-sm text-[#767676]">
                    <strong class="text-[#646464]"><?= esc($a['section']['submitted']) ?>/<?= esc($a['section']['students']) ?></strong> submitted
                </span>
            <?php endif; ?>

            <button
                type="button"
                data-view-assessment-button
                data-assessment="<?= esc($a['id']) ?>"
                title="Preview assessment"
                class="text-gray-500 transition hover:text-[#0040A6]">

                <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6 transition-transform duration-200 hover:scale-110" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
                    <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                </svg>

            </button>

            <button
                type="button"
                data-edit-assessment-button
                data-assessment="<?= esc($a['id']) ?>"
                title="Edit assessment"
                class="text-gray-500 transition hover:text-[#0040A6]">

                <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6 transition-transform duration-200 hover:scale-110" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5M16.5 3.5a2.121 2.121 0 113 3L12 14l-4 1 1-4 7.5-7.5z" />
                </svg>

            </button>

            <?= icon('start', 'h-7 w-7 text-gray-500 transition duration-200 ease-out group-hover:text-[#0040A6] group-hover:translate-x-0.5 group-hover:scale-125 group-focus-visible:text-[#0040A6] group-focus-visible:scale-125') ?>

        <?php elseif ($student['key'] === 'graded' && $student['score'] !== null): ?>

            <span class="rounded-full bg-emerald-50 px-4 py-1.5 text-sm font-bold text-emerald-700">
                <?= esc((int) $student['score']) ?>/<?= esc($totalPoints) ?>
            </span>

        <?php elseif ($student['key'] === 'partially_graded' && $student['score'] !== null): ?>

            <!-- Auto-graded part so far; essays / uploads still being graded. -->
            <span
                class="rounded-full bg-[#FFF1D6] px-4 py-1.5 text-sm font-bold text-[#946200]"
                title="Auto-graded so far — the rest is still being graded">
                <?= esc((int) $student['score']) ?>/<?= esc($totalPoints) ?> so far
            </span>

        <?php elseif ($student['key'] === 'resubmitted' && $student['score'] !== null): ?>

            <!-- The previous grade stands until the resubmission is graded. -->
            <span
                class="rounded-full bg-gray-100 px-4 py-1.5 text-sm font-bold text-gray-500"
                title="Grade for your previous submission">
                <?= esc((int) $student['score']) ?>/<?= esc($totalPoints) ?>
            </span>

        <?php elseif ($student['key'] === 'locked'): ?>

            <?= icon('lock', 'h-6 w-6') ?>

        <?php else: ?>

            <?= icon(
                'start',
                'h-7 w-7 text-gray-500 transition duration-200 ease-out group-hover:text-[#0040A6] group-hover:translate-x-0.5 group-hover:scale-125 group-focus-visible:text-[#0040A6] group-focus-visible:scale-125'
                . ($student['actionable'] ? '' : ' opacity-40')
            ) ?>

        <?php endif; ?>

    </div>

</div>
