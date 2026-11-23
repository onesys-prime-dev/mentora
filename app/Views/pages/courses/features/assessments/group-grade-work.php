<?php
/**
 * Grade Work for a group activity (group activities, screen 2B): the activity's groups on the left, one group's work
 * and every member's grade on the right, saved together. GradeWorkService::gradeWork draws this instead of the
 * per-student page when the assignment uses groups. The server draws the frame and the dialogs (4D);
 * resources/js/pages/courses/groups/grading.js fills them from the group-grading JSON and sends every change.
 * With flags.canWrite false (finalized grades, a closed course, no grading permission) nothing can be changed.
 *
 * @var array<string, mixed> $currentAssessment
 */
$field = static fn (array $params): string => view('components/form-field', $params, ['saveData' => false]);

$cancel    = '<button type="button" data-dialog-close class="rounded-full border border-gray-300 px-5 py-2 text-sm font-semibold text-[#3D3D3D] transition hover:bg-gray-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/50 pointer-coarse:min-h-11">Cancel</button>';
$submit    = static fn (string $text): string => '<button type="submit" data-dialog-label class="rounded-full bg-brand px-5 py-2 text-sm font-semibold text-white transition hover:bg-brand-dark disabled:cursor-not-allowed disabled:opacity-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/50 focus-visible:ring-offset-2 pointer-coarse:min-h-11">' . esc($text) . '</button>';
$formError = '<p class="text-xs font-medium text-[#C33939]" role="alert" data-form-error hidden></p>';
$buttons   = static fn (string $text): string => '<div class="flex flex-wrap items-center justify-end gap-3">' . $cancel . $submit($text) . '</div>';

/** @param array<string, string> $options value => [label, help]; the first is the default */
$choice = static function (string $legend, array $options): string {
    $html  = '<fieldset class="flex flex-col gap-2" data-field="decision"><legend class="mb-1 text-xs font-medium text-gray-600">' . esc($legend) . '</legend>';
    $first = true;

    foreach ($options as $value => [$label, $help]) {
        $html .= '<label class="flex cursor-pointer items-start gap-3 rounded-xl border border-gray-200 p-3 text-sm text-[#3D3D3D] has-checked:border-brand has-checked:bg-brand-tint pointer-coarse:min-h-11">'
            . '<input type="radio" name="decision" value="' . esc($value) . '" class="mt-1 accent-brand"' . ($first ? ' checked' : '') . '>'
            . '<span class="flex flex-col gap-0.5"><b class="font-semibold text-[#1A1C1E]">' . esc($label) . '</b><span class="text-xs text-[#646464]">' . esc($help) . '</span></span></label>';
        $first = false;
    }

    return $html . '<p class="text-xs font-medium text-[#C33939]" data-field-error hidden></p></fieldset>';
};

$lateForm = '<form class="flex flex-col gap-4" novalidate data-gg-late-form>'
    . view('components/alert', ['tone' => 'info', 'text' => 'This group was graded before they joined.'], ['saveData' => false])
    . $choice('Their grade', [
        'separate' => ['Grade separately (default)', 'They stay ungraded until you save the group\'s grades again with their scores.'],
        'inherit'  => ['Inherit the group grade', 'They get the group\'s part of the grade now. Individual criteria are not copied.'],
    ])
    . $field(['name' => 'reason', 'label' => 'Reason', 'type' => 'textarea', 'required' => true, 'maxlength' => 500])
    . $formError . $buttons('Save decision') . '</form>';

$dropForm = '<form class="flex flex-col gap-4" novalidate data-gg-drop-form>'
    . '<div data-gg-drop-score>' . view('components/alert', ['tone' => 'info', 'text' => 'This group was graded.'], ['saveData' => false]) . '</div>'
    . $choice('Their score', [
        'keep'   => ['Keep it on their record (default)', 'The score stays in their gradebook.'],
        'excuse' => ['Excuse it', 'It leaves their gradebook average. The score is kept in the history.'],
    ])
    . $field(['name' => 'reason', 'label' => 'Reason', 'type' => 'textarea', 'required' => true, 'maxlength' => 500])
    . $formError . $buttons('Save decision') . '</form>';
?>

<div class="flex min-w-0 flex-1 flex-col p-5 sm:p-8 lg:p-10">

    <div class="sticky-bar-top flex items-center justify-between gap-3 border-b border-gray-200 pb-4">

        <div class="flex min-w-0 items-center gap-3">

            <button
                type="button"
                data-back-to-assessments
                data-assessment-id="<?= esc($currentAssessment['id']) ?>"
                aria-label="Back"
                class="inline-flex shrink-0 items-center justify-center rounded-lg p-2 text-gray-500 transition hover:bg-gray-100 pointer-coarse:min-h-11 pointer-coarse:min-w-11">

                <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7" />
                </svg>

            </button>

            <div class="flex min-w-0 items-center gap-2">
                <span class="shrink-0 text-xl font-bold text-brand max-md:hidden">Grade Work</span>
                <span class="text-gray-300 max-md:hidden">·</span>
                <span class="truncate text-lg font-semibold text-[#646464]"><?= esc($currentAssessment['title']) ?></span>
            </div>

        </div>

        <?= view('components/status-badge', ['label' => 'Group activity', 'tone' => 'info', 'icon' => 'users'], ['saveData' => false]) ?>

    </div>

    <div class="flex flex-col gap-4 py-6" data-group-grading data-assessment-id="<?= esc((string) $currentAssessment['id']) ?>">

        <?= view('pages/courses/features/assessments/partials/group-grading-frame', [], ['saveData' => false]) ?>

        <?= view('components/dialog', ['id' => 'gg-late-dialog', 'title' => 'A student joined after grading', 'body' => $lateForm, 'size' => 'sm', 'variant' => 'default'], ['saveData' => false]) ?>
        <?= view('components/dialog', ['id' => 'gg-drop-dialog', 'title' => 'A graded student left the group', 'body' => $dropForm, 'size' => 'sm', 'variant' => 'default'], ['saveData' => false]) ?>
        <?= view('components/confirm', [
            'id' => 'gg-reopen-confirm', 'title' => 'Reopen grading for this group?', 'action' => '#', 'confirmLabel' => 'Reopen grading', 'tone' => 'danger', 'ajax' => true,
            'text' => 'Every member\'s grade is taken off until you save again. Their scores, adjustments and notes stay filled in.',
        ], ['saveData' => false]) ?>

    </div>

</div>
