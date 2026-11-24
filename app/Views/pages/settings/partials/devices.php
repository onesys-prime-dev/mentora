<?php
/**
 * Settings: the Signed-in devices card (D14). One action, behind a confirm: end every other session.
 * There is no device list (D14).
 */
?>
<h2 class="text-lg font-bold text-brand">Signed-in devices</h2>
<p class="text-sm text-[#646464]">Sign out everywhere except this browser.</p>

<div>
    <button type="button" data-dialog-open="sign-out-others" class="rounded-full border border-gray-300 px-5 py-2 text-sm font-semibold text-[#3D3D3D] transition hover:bg-gray-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/50 max-sm:w-full pointer-coarse:min-h-11">Sign out of all other devices</button>
</div>

<?= view('components/confirm', [
    'id'           => 'sign-out-others',
    'title'        => 'Sign out of all other devices?',
    'text'         => 'Every other browser and device signed in to your account is signed out. This one stays signed in, and we email you a notice.',
    'action'       => site_url('settings/sign-out-others'),
    'confirmLabel' => 'Sign out of all other devices',
], ['saveData' => false]) ?>
