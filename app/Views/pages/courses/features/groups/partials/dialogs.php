<?php
/**
 * The Groups tab's dialogs. Drawn only for someone who may change groups; groups/index.js opens them, fills them
 * and sends them as JSON to the group-sets endpoints (a 422 shows its errors beside the fields).
 */
$field = static fn (array $params): string => view('components/form-field', $params, ['saveData' => false]);

$cancel = '<button type="button" data-dialog-close class="rounded-full border border-gray-300 px-5 py-2 text-sm font-semibold text-[#3D3D3D] transition hover:bg-gray-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/50 pointer-coarse:min-h-11">Cancel</button>';
$submit = static fn (string $label): string => '<button type="submit" data-dialog-label class="rounded-full bg-brand px-5 py-2 text-sm font-semibold text-white transition hover:bg-brand-dark disabled:cursor-not-allowed disabled:opacity-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/50 focus-visible:ring-offset-2 pointer-coarse:min-h-11">' . esc($label) . '</button>';
$formError = '<p class="text-xs font-medium text-[#C33939]" role="alert" data-form-error hidden></p>';
$buttons = static fn (string $label): string => '<div class="flex flex-wrap items-center justify-end gap-3">' . $cancel . $submit($label) . '</div>';

$setForm = '<form class="flex flex-col gap-4" novalidate data-group-set-form>'
    . $field(['name' => 'name', 'label' => 'Name', 'required' => true, 'maxlength' => 150, 'placeholder' => 'Project teams'])
    . $field(['name' => 'formation_mode', 'label' => 'How groups are formed', 'type' => 'select', 'options' => [
        'manual'      => 'Manual: you place the students',
        'auto'        => 'Auto-generated',
        'self_signup' => 'Self-signup: students join a group',
        'proposed'    => 'Proposed by students',
    ]])
    . '<div class="grid grid-cols-2 gap-3">'
    . $field(['name' => 'min_size', 'label' => 'Smallest group', 'type' => 'number', 'value' => '1', 'attrs' => 'min="1" max="30"'])
    . $field(['name' => 'max_size', 'label' => 'Largest group', 'type' => 'number', 'value' => '5', 'attrs' => 'min="1" max="30"'])
    . '</div>'
    . '<div class="grid grid-cols-1 gap-3 sm:grid-cols-2" data-signup-window hidden>'
    . $field(['name' => 'signup_opens_at', 'label' => 'Sign-up opens', 'type' => 'datetime-local'])
    . $field(['name' => 'signup_closes_at', 'label' => 'Sign-up closes', 'type' => 'datetime-local'])
    . '</div>'
    . $formError
    . $buttons('Save')
    . '</form>';

$nameForm = '<form class="flex flex-col gap-4" novalidate data-group-name-form>'
    . $field(['name' => 'name', 'label' => 'Group name', 'required' => true, 'maxlength' => 150])
    . $formError
    . $buttons('Save')
    . '</form>';

$moveForm = '<form class="flex flex-col gap-4" novalidate data-group-move-form>'
    . $field(['name' => 'to_group', 'label' => 'Move to', 'type' => 'select', 'help' => 'Full groups cannot be chosen.'])
    . $formError
    . $buttons('Move')
    . '</form>';
?>

<?= view('components/dialog', ['id' => 'group-set-dialog', 'title' => 'New group set', 'body' => $setForm], ['saveData' => false]) ?>

<?= view('components/dialog', ['id' => 'group-name-dialog', 'title' => 'Add group', 'body' => $nameForm, 'size' => 'sm', 'variant' => 'default'], ['saveData' => false]) ?>

<?= view('components/dialog', ['id' => 'group-move-dialog', 'title' => 'Move student', 'body' => $moveForm, 'size' => 'sm', 'variant' => 'default'], ['saveData' => false]) ?>

<?= view('components/confirm', [
    'id'           => 'group-set-archive-confirm',
    'title'        => 'Archive this group set?',
    'text'         => 'It leaves this list. Its groups and members are kept.',
    'action'       => '#',
    'confirmLabel' => 'Archive set',
    'tone'         => 'danger',
    'ajax'         => true,
], ['saveData' => false]) ?>

<?= view('components/confirm', [
    'id'           => 'group-delete-confirm',
    'title'        => 'Delete this group?',
    'text'         => 'Its members go back to Ungrouped.',
    'action'       => '#',
    'confirmLabel' => 'Delete group',
    'tone'         => 'danger',
    'ajax'         => true,
], ['saveData' => false]) ?>
