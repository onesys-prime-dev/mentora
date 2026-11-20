<?php
/**
 * The student's "Group activity" card on an assignment (group activities, screens 3A, 3C, 3E). Always drawn
 * hidden on a student's assignment page; resources/js/pages/courses/groups/student-card.js shows it while it
 * loads, fills it from the my-group JSON, and removes it when the assignment is not a group activity.
 * The alert templates are the shared alert component, cloned by the script with its own text.
 *
 * @var array<string, mixed> $currentAssessment
 */
$alert = static fn (string $tone): string => '<template data-sg-alert="' . $tone . '">' . view('components/alert', ['tone' => $tone, 'text' => ''], ['saveData' => false]) . '</template>';

$field     = static fn (array $params): string => view('components/form-field', $params, ['saveData' => false]);
$cancel    = '<button type="button" data-dialog-close class="rounded-full border border-gray-300 px-5 py-2 text-sm font-semibold text-[#3D3D3D] transition hover:bg-gray-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/50 pointer-coarse:min-h-11">Cancel</button>';
$buttons   = static fn (string $text): string => '<p class="text-xs font-medium text-[#C33939]" role="alert" data-form-error hidden></p><div class="flex flex-wrap items-center justify-end gap-3">' . $cancel
    . '<button type="submit" data-dialog-label class="rounded-full bg-brand px-5 py-2 text-sm font-semibold text-white transition hover:bg-brand-dark disabled:cursor-not-allowed disabled:opacity-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/50 focus-visible:ring-offset-2 pointer-coarse:min-h-11">' . esc($text) . '</button></div>';

$renameForm = '<form class="flex flex-col gap-4" novalidate>'
    . $field(['name' => 'name', 'label' => 'Group name', 'required' => true, 'maxlength' => 150])
    . $buttons('Save') . '</form>';

$proposeForm = '<form class="flex flex-col gap-4" novalidate>'
    . $field(['name' => 'name', 'label' => 'Group name', 'required' => true, 'maxlength' => 150])
    . '<fieldset class="flex min-w-0 flex-col gap-1" data-field="invitee_ids">'
    . '<legend class="text-sm font-semibold text-[#3D3D3D]">Invite classmates</legend>'
    . '<p class="text-xs text-[#646464]" data-sg-size-hint></p>'
    . '<div class="flex max-h-60 flex-col overflow-y-auto" data-sg-invitees></div>'
    . '<p class="text-xs text-[#646464]">Each classmate must accept. Your instructor then approves the group.</p>'
    . '<p class="text-xs font-medium text-[#C33939]" data-field-error hidden></p></fieldset>'
    . $buttons('Send invitations') . '</form>';
?>

<div class="flex flex-col gap-3" id="student-group" data-student-group data-assessment-id="<?= esc((string) $currentAssessment['id']) ?>" hidden>

    <div class="flex items-center gap-3">
        <?= icon('users', 'h-5 w-5') ?>
        <span class="font-bold text-lg text-brand">Group activity</span>
    </div>

    <div class="flex flex-col gap-4 rounded-2xl border border-gray-200 bg-white p-5" data-sg-body></div>

    <p class="sr-only" aria-live="polite" data-sg-live></p>

    <?= $alert('warning') ?>
    <?= $alert('info') ?>

    <?= view('components/confirm', [
        'id'           => 'sg-leave-confirm',
        'title'        => 'Leave this group?',
        'text'         => 'You will leave your group.',
        'action'       => '#',
        'confirmLabel' => 'Leave group',
        'tone'         => 'danger',
        'ajax'         => true,
    ], ['saveData' => false]) ?>

    <?= view('components/dialog', ['id' => 'sg-rename-dialog', 'title' => 'Rename group', 'body' => $renameForm, 'size' => 'sm', 'variant' => 'default'], ['saveData' => false]) ?>
    <?= view('components/dialog', ['id' => 'sg-propose-dialog', 'title' => 'Propose a new group', 'body' => $proposeForm, 'size' => 'sm', 'variant' => 'default'], ['saveData' => false]) ?>

</div>
