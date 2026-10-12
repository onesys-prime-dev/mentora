<?php
/**
 * The "confirm your password" dialog, for pages with guarded actions that
 * submit from a dialog (shared/dialog.js opens it when the server answers
 * "reauth_required", then repeats the action). Pages opt in with
 * 'reauthDialog' => true. Without JavaScript the guarded action simply sends
 * the person to the confirm page instead.
 */
$reauthUser   = auth()->user();
$reauthAction = $reauthUser !== null && $reauthUser->inGroup('admin') ? 'admin/reauth' : 'reauth';

$reauthBody = '<form method="post" action="' . esc(site_url($reauthAction)) . '" data-reauth-form class="flex flex-col gap-4">'
    . csrf_field()
    . '<p>This needs your password again. It stays confirmed for a few minutes.</p>'
    . view('components/form-field', ['name' => 'password', 'label' => 'Password', 'type' => 'password', 'required' => true, 'attrs' => 'autocomplete="current-password" autofocus'], ['saveData' => false])
    . '<p class="text-xs font-medium text-[#C33939]" data-form-error hidden></p>'
    . '<div class="flex flex-wrap items-center justify-end gap-3">'
    . '<button type="button" data-dialog-close class="rounded-full border border-gray-300 px-5 py-2 text-sm font-semibold text-[#3D3D3D] transition hover:bg-gray-50">Cancel</button>'
    . '<button type="submit" class="rounded-full bg-[#0040A6] px-5 py-2 text-sm font-semibold text-white transition hover:bg-[#002068]">Confirm</button>'
    . '</div></form>';
?>
<?= view('components/dialog', ['id' => 'reauth-dialog', 'title' => 'Confirm your password', 'body' => $reauthBody, 'size' => 'sm'], ['saveData' => false]) ?>
