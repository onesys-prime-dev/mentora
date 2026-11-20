<?php
/**
 * The assignment editor's "Group activity" card (group activities, screen 2A). Drawn for assignments only
 * (assessment-types/editor/assignment.php includes it; a quiz never does). The server draws the controls and
 * dialogs; resources/js/pages/courses/groups/activity-panel.js fills them from the activity-groups JSON, draws
 * the groups with the Groups tab's board, and sends every change. With flags.canWrite false it disables them all.
 *
 * @var array<string, mixed> $currentAssessment
 */
$field = static fn (array $params): string => view('components/form-field', $params, ['saveData' => false]);

$switch = static fn (string $name, string $label): string => '<div class="flex flex-col gap-1 border-t border-gray-100 pt-3" data-field="' . esc($name) . '">'
    . '<div class="flex items-center justify-between gap-3">'
    . '<label for="ga-' . esc($name) . '" class="text-sm text-[#3D3D3D]">' . esc($label) . '</label>'
    . '<label class="relative inline-flex shrink-0 cursor-pointer items-center pointer-coarse:min-h-11">'
    . '<input type="checkbox" id="ga-' . esc($name) . '" name="' . esc($name) . '" class="peer sr-only">'
    . '<div class="h-6 w-11 rounded-full bg-gray-300 transition-colors peer-checked:bg-brand peer-focus-visible:ring-2 peer-focus-visible:ring-brand/50 peer-disabled:opacity-50"></div>'
    . '<div class="absolute left-1 top-1/2 h-4 w-4 -translate-y-1/2 rounded-full bg-white transition-transform peer-checked:translate-x-5"></div>'
    . '</label></div>'
    . '<p class="text-xs font-medium text-[#C33939]" data-field-error hidden></p></div>';

$link = 'inline-flex shrink-0 items-center gap-1.5 rounded-lg px-3 py-1.5 text-sm font-semibold text-brand transition-colors hover:bg-brand/10 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/50 pointer-coarse:min-h-11';
$label = 'font-heading text-xs font-semibold uppercase tracking-wide text-[#646464]';

$cancel    = '<button type="button" data-dialog-close class="rounded-full border border-gray-300 px-5 py-2 text-sm font-semibold text-[#3D3D3D] transition hover:bg-gray-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/50 pointer-coarse:min-h-11">Cancel</button>';
$submit    = static fn (string $text, string $tone = 'primary'): string => '<button type="submit" data-dialog-label class="rounded-full px-5 py-2 text-sm font-semibold text-white transition disabled:cursor-not-allowed disabled:opacity-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/50 focus-visible:ring-offset-2 pointer-coarse:min-h-11 '
    . ($tone === 'danger' ? 'bg-[#C33939] hover:bg-[#A82E2E]' : 'bg-brand hover:bg-brand-dark') . '">' . esc($text) . '</button>';
$formError = '<p class="text-xs font-medium text-[#C33939]" role="alert" data-form-error hidden></p>';
$buttons   = static fn (string $text, string $tone = 'primary'): string => '<div class="flex flex-wrap items-center justify-end gap-3">' . $cancel . $submit($text, $tone) . '</div>';

$attachForm = '<form class="flex flex-col gap-4" novalidate data-ga-attach-form>'
    . $field(['name' => 'set_id', 'label' => 'Group set', 'type' => 'select', 'help' => 'The set\'s groups are copied into this assignment. Later edits to the set will not change this activity.'])
    . $formError . $buttons('Make it a group activity') . '</form>';

$unlockForm = '<form class="flex flex-col gap-4" novalidate data-ga-unlock-form>'
    . view('components/alert', ['tone' => 'warning', 'text' => 'This group has submitted. Unlocking lets you change its members, leader or the submission style. Every change is recorded.'], ['saveData' => false])
    . $field(['name' => 'reason', 'label' => 'Reason', 'type' => 'textarea', 'required' => true, 'maxlength' => 500])
    . $formError . $buttons('Unlock group', 'danger') . '</form>';

$lateForm = '<form class="flex flex-col gap-4" novalidate data-ga-late-form>'
    . '<p class="text-sm text-[#646464]">This group has submitted. A student who joined the course late can still be added; the change is recorded.</p>'
    . $field(['name' => 'student_user_id', 'label' => 'Student', 'type' => 'select', 'required' => true, 'help' => 'Only students who are not in a group are listed.'])
    . $field(['name' => 'reason', 'label' => 'Reason', 'type' => 'textarea', 'required' => true, 'maxlength' => 500])
    . $formError . $buttons('Add to group') . '</form>';

$nameForm = '<form class="flex flex-col gap-4" novalidate data-ga-name-form>'
    . $field(['name' => 'name', 'label' => 'Group name', 'required' => true, 'maxlength' => 150])
    . $formError . $buttons('Save') . '</form>';

$moveForm = '<form class="flex flex-col gap-4" novalidate data-ga-move-form>'
    . $field(['name' => 'to_group', 'label' => 'Move to', 'type' => 'select', 'help' => 'Full and frozen groups cannot be chosen.'])
    . $formError . $buttons('Move') . '</form>';

$copyForm = '<form class="flex flex-col gap-4" novalidate data-ga-copy-form>'
    . '<p class="text-sm text-[#646464]">This assignment\'s groups are replaced by a copy of the other activity\'s groups.</p>'
    . $field(['name' => 'from_assessment_id', 'label' => 'Copy from', 'type' => 'select'])
    . $formError . $buttons('Copy groups', 'danger') . '</form>';

$declineForm = '<form class="flex flex-col gap-4" novalidate data-ga-decline-form>'
    . '<p class="text-sm text-[#646464]">The proposal is removed and its members are free to join or propose another group. The reason is kept with the change.</p>'
    . $field(['name' => 'reason', 'label' => 'Reason', 'type' => 'textarea', 'required' => true, 'maxlength' => 500])
    . $formError . $buttons('Decline proposal', 'danger') . '</form>';

$leaderForm = '<form class="flex flex-col gap-4" novalidate data-ga-leader-form>'
    . $field(['name' => 'student_user_id', 'label' => 'Leader', 'type' => 'select', 'required' => true, 'help' => 'This replaces any leader the group chose. The change is recorded.'])
    . $formError . $buttons('Set leader') . '</form>';

$extendForm = '<form class="flex flex-col gap-4" novalidate data-ga-extend-form>'
    . '<p class="text-sm text-[#646464]" data-ga-extend-current></p>'
    . $field(['name' => 'until', 'label' => 'New deadline', 'type' => 'datetime-local', 'required' => true, 'help' => 'It must be later than the current deadline. Only this group gets the extra time.'])
    . $field(['name' => 'reason', 'label' => 'Reason', 'type' => 'textarea', 'required' => true, 'maxlength' => 500])
    . $formError . $buttons('Extend deadline') . '</form>';

$confirm = static fn (string $id, string $title, string $text, string $button, string $tone = 'danger'): string => view('components/confirm', [
    'id' => $id, 'title' => $title, 'text' => $text, 'action' => '#', 'confirmLabel' => $button, 'tone' => $tone, 'ajax' => true,
], ['saveData' => false]);
?>

<div class="flex flex-col gap-3" data-group-activity data-assessment-id="<?= esc((string) $currentAssessment['id']) ?>">

    <div class="flex items-center justify-between gap-3">

        <div class="flex items-center gap-3">
            <?= icon('users', 'h-5 w-5') ?>
            <span class="font-bold text-lg text-brand" id="ga-title">Group activity</span>
        </div>

        <label class="relative inline-flex shrink-0 cursor-pointer items-center pointer-coarse:min-h-11">
            <input type="checkbox" class="peer sr-only" role="switch" aria-labelledby="ga-title" data-ga-toggle disabled>
            <div class="h-6 w-11 rounded-full bg-gray-300 transition-colors peer-checked:bg-brand peer-focus-visible:ring-2 peer-focus-visible:ring-brand/50 peer-disabled:opacity-50"></div>
            <div class="absolute left-1 top-1/2 h-4 w-4 -translate-y-1/2 rounded-full bg-white transition-transform peer-checked:translate-x-5"></div>
        </label>

    </div>

    <div class="flex flex-col gap-4 rounded-2xl border border-gray-200 bg-white p-5">

        <div data-ga-lock hidden><?= view('components/lock-note', ['kind' => 'closed'], ['saveData' => false]) ?></div>

        <div data-ga-status></div>

        <div data-ga-empty hidden>
            <?= view('components/empty-state', [
                'icon' => 'users',
                'title' => 'Students work on this alone',
                'text' => 'Turn on Group activity to let students hand in work as a group. The groups are copied from a group set or made here, and changing the set later leaves this assignment as it is.',
            ], ['saveData' => false]) ?>
        </div>

        <form class="flex flex-col gap-4" novalidate data-ga-settings hidden>
            <fieldset class="flex min-w-0 flex-col gap-4" data-ga-fields>
                <legend class="sr-only">Group activity settings</legend>

                <div class="flex flex-wrap items-center gap-x-1 gap-y-1 text-xs text-[#646464]">
                    <span data-ga-source></span>
                    <button type="button" class="<?= $link ?>" data-ga-action="recopy">Re-copy from set</button>
                    <button type="button" class="<?= $link ?>" data-ga-action="copy-from">Copy from another activity</button>
                </div>

                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <?= $field(['name' => 'ungrouped_policy', 'label' => 'Students without a group', 'type' => 'select', 'options' => ['block' => 'Must join a group', 'solo' => 'May work alone'], 'help' => 'Changing this after a group has submitted asks you to confirm.']) ?>
                    <?= $field(['name' => 'submission_style', 'label' => 'Submission style', 'type' => 'select', 'options' => ['shared' => 'Shared: any member submits', 'leader_only' => 'Leader only', 'per_member' => 'Per member'], 'help' => 'Locks after the first submission.']) ?>
                    <?= $field(['name' => 'grading_mode', 'label' => 'Grading', 'type' => 'select', 'options' => ['collective' => 'One score for the group', 'collective_adjust' => 'Group score with a per-student adjustment', 'individual' => 'Each student scored on their own']]) ?>
                    <?= $field(['name' => 'formation_mode', 'label' => 'How are groups formed?', 'type' => 'select', 'options' => ['manual' => 'Instructor places students', 'auto' => 'Auto-generated', 'self_signup' => 'Students sign up', 'proposed' => 'Students propose groups'], 'help' => 'Locks after the first submission.']) ?>
                    <div class="grid grid-cols-2 gap-3">
                        <?= $field(['name' => 'min_size', 'label' => 'Smallest group', 'type' => 'number', 'attrs' => 'min="1" max="30"']) ?>
                        <?= $field(['name' => 'max_size', 'label' => 'Largest group', 'type' => 'number', 'attrs' => 'min="1" max="30"']) ?>
                    </div>
                </div>

                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2" data-ga-signup-window hidden>
                    <?= $field(['name' => 'signup_opens_at', 'label' => 'Signup opens', 'type' => 'datetime-local']) ?>
                    <?= $field(['name' => 'signup_closes_at', 'label' => 'Signup closes', 'type' => 'datetime-local']) ?>
                </div>

                <div class="flex flex-col gap-3">
                    <h4 class="<?= $label ?>">Rules for non-leaders once the leader is final</h4>
                    <?= $switch('nonleader_can_rename', 'Can rename the group') ?>
                    <?= $switch('nonleader_can_vote', 'Can nominate or vote on a new leader') ?>
                    <?= $switch('nonleader_can_leave', 'Can leave the group (before the first submission)') ?>
                    <?= $switch('groups_visible', 'Students can see other groups') ?>
                </div>
            </fieldset>
        </form>

        <div data-ga-proposals hidden></div>

        <div id="ga-board" data-ga-board></div>

    </div>

    <p class="sr-only" aria-live="polite" data-groups-live></p>

    <?= view('components/dialog', ['id' => 'ga-attach-dialog', 'title' => 'Make this a group activity', 'body' => $attachForm, 'size' => 'sm', 'variant' => 'default'], ['saveData' => false]) ?>
    <?= view('components/dialog', ['id' => 'ga-unlock-dialog', 'title' => 'Unlock this group?', 'body' => $unlockForm, 'size' => 'sm', 'variant' => 'default'], ['saveData' => false]) ?>
    <?= view('components/dialog', ['id' => 'ga-late-dialog', 'title' => 'Add a late enrollee', 'body' => $lateForm, 'size' => 'sm', 'variant' => 'default'], ['saveData' => false]) ?>
    <?= view('components/dialog', ['id' => 'ga-name-dialog', 'title' => 'Add group', 'body' => $nameForm, 'size' => 'sm', 'variant' => 'default'], ['saveData' => false]) ?>
    <?= view('components/dialog', ['id' => 'ga-move-dialog', 'title' => 'Move student', 'body' => $moveForm, 'size' => 'sm', 'variant' => 'default'], ['saveData' => false]) ?>
    <?= view('components/dialog', ['id' => 'ga-copy-dialog', 'title' => 'Copy groups from another activity', 'body' => $copyForm, 'size' => 'sm', 'variant' => 'default'], ['saveData' => false]) ?>
    <?= view('components/dialog', ['id' => 'ga-decline-dialog', 'title' => 'Decline this proposal?', 'body' => $declineForm, 'size' => 'sm', 'variant' => 'default'], ['saveData' => false]) ?>
    <?= view('components/dialog', ['id' => 'ga-extend-dialog', 'title' => 'Extend the deadline', 'body' => $extendForm, 'size' => 'sm', 'variant' => 'default'], ['saveData' => false]) ?>
    <?= view('components/dialog', ['id' => 'ga-leader-dialog', 'title' => 'Set leader', 'body' => $leaderForm, 'size' => 'sm', 'variant' => 'default'], ['saveData' => false]) ?>
    <?= $confirm('ga-detach-confirm', 'Stop using groups here?', 'This removes the activity\'s groups. The group set itself is kept.', 'Remove groups') ?>
    <?= $confirm('ga-policy-confirm', 'Change what happens to students without a group?', 'A group has already submitted.', 'Change it', 'primary') ?>
    <?= $confirm('ga-delete-confirm', 'Delete this group?', 'Its members go back to Ungrouped.', 'Delete group') ?>
    <?= $confirm('ga-recopy-confirm', 'Re-copy the groups from the set?', 'This activity\'s groups are replaced by a fresh copy of the set\'s groups.', 'Re-copy') ?>

</div>
