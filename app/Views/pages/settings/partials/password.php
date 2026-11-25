<?php
/**
 * Settings: the Password card (D13). The change form, and the emailed-link fallback for a forgotten password.
 *
 * @var int                   $minLength the shortest password in force for this person
 * @var bool                  $hasSchool whether a school sets that rule (the system admin has none)
 * @var array<string, string> $errors    field => message, from the last refused attempt
 */
$meter = '<div class="flex max-w-xs gap-1 pt-1" aria-hidden="true" data-password-meter>'
    . str_repeat('<i class="h-1 flex-1 rounded-full bg-gray-200"></i>', 4) . '</div>'
    . '<span>At least ' . $minLength . ' characters' . ($hasSchool ? " (your school's rule)" : '') . '.</span> <span data-password-strength aria-live="polite"></span>';
?>
<h2 class="text-lg font-bold text-brand">Password</h2>
<p class="text-sm text-[#646464]">Changing it signs you out everywhere else and emails you a notice.</p>

<form action="<?= esc(site_url('settings/password'), 'attr') ?>" method="post" class="flex flex-col gap-4" data-password-form>
    <?= csrf_field() ?>

    <?= view('pages/settings/partials/password-field', ['name' => 'current_password', 'label' => 'Current password', 'autocomplete' => 'current-password', 'error' => $errors['current_password'] ?? ''], ['saveData' => false]) ?>
    <?= view('pages/settings/partials/password-field', ['name' => 'password', 'label' => 'New password', 'autocomplete' => 'new-password', 'error' => $errors['password'] ?? '', 'help' => $meter, 'attrs' => 'minlength="' . $minLength . '" data-password-new data-min="' . $minLength . '"'], ['saveData' => false]) ?>
    <?= view('pages/settings/partials/password-field', ['name' => 'password_confirm', 'label' => 'Confirm new password', 'autocomplete' => 'new-password', 'error' => $errors['password_confirm'] ?? ''], ['saveData' => false]) ?>

    <div class="flex flex-wrap items-center gap-x-4 gap-y-2 max-sm:flex-col max-sm:items-stretch">
        <button type="submit" class="rounded-full bg-brand px-5 py-2 text-sm font-semibold text-white transition hover:bg-brand-dark focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/50 pointer-coarse:min-h-11">Change password</button>
        <button type="submit" form="settings-password-link" formnovalidate class="rounded-full px-2 py-2 text-sm font-semibold text-brand underline-offset-2 transition hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/50 pointer-coarse:min-h-11">Forgot your current password? Email me a link</button>
    </div>
</form>

<?php // Its own form, so the link can be asked for without typing anything; the button above submits it. ?>
<form id="settings-password-link" action="<?= esc(site_url('settings/password-link'), 'attr') ?>" method="post" hidden>
    <?= csrf_field() ?>
</form>
