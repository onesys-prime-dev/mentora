<?php

/*
|--------------------------------------------------------------------------
| Confirm — "are you sure?" that says what will happen
|--------------------------------------------------------------------------
| Params: id, title, text (required: the consequence in plain words), action
| (the POST url, required), confirmLabel (required: a verb, "Deactivate"),
| tone (danger|primary), typeToConfirm (optional exact text the person must
| type, for the irreversible ones), fields (optional hidden inputs, html).
|
| Open it with <button data-dialog-open="<id>">. A real form post: works
| without JavaScript, and with a CSRF field like every other form.
|--------------------------------------------------------------------------
*/

$tone          = $tone ?? 'primary';
$typeToConfirm = $typeToConfirm ?? '';
$fields        = $fields ?? '';

$button = $tone === 'danger'
    ? 'bg-[#C33939] hover:bg-[#A82E2E]'
    : 'bg-[#0040A6] hover:bg-[#002068]';

$body = '<p data-dialog-text>' . esc($text) . '</p>'
    . ($typeToConfirm !== ''
        ? '<label class="flex flex-col gap-1 text-xs font-medium text-gray-600">Type <strong class="text-[#1A1C1E]">' . esc($typeToConfirm) . '</strong> to confirm'
            . '<input type="text" autocomplete="off" data-confirm-match="' . esc($typeToConfirm) . '" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-[#0040A6]/60 focus:outline-none focus:ring-2 focus:ring-[#0040A6]/40"></label>'
        : '');

$footer = '<button type="button" data-dialog-close class="rounded-full border border-gray-300 px-5 py-2 text-sm font-semibold text-[#3D3D3D] transition hover:bg-gray-50">Cancel</button>'
    . '<button type="submit" data-confirm-submit data-dialog-label class="rounded-full px-5 py-2 text-sm font-semibold text-white transition disabled:cursor-not-allowed disabled:opacity-50 ' . $button . '">' . esc($confirmLabel) . '</button>';

$form = '<form method="post" action="' . esc($action) . '" class="contents">' . csrf_field() . $fields
    . '<div class="flex flex-col gap-4">' . $body . '</div>'
    . '<div class="mt-5 flex flex-wrap items-center justify-end gap-3">' . $footer . '</div></form>';
?>

<?= view('components/dialog', ['id' => $id, 'title' => $title, 'body' => $form, 'size' => 'sm'], ['saveData' => false]) ?>
