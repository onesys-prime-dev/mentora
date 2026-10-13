<?php
/**
 * Turns the redirect messages (session "message" and "error") into toasts,
 * for pages that opt in with 'flashToasts' => true. Pages that print the
 * messages themselves do not opt in, so nothing is shown twice.
 * shared/toast.js shows and removes these elements on load.
 */
?>
<?php if (session()->has('message')): ?>
    <div hidden data-toast-message data-tone="success"><?= esc((string) session('message')) ?></div>
<?php endif; ?>
<?php if (session()->has('error')): ?>
    <div hidden data-toast-message data-tone="danger"><?= esc((string) session('error')) ?></div>
<?php endif; ?>
