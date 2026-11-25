<?php
/**
 * A system administrator edits their own official details (FR7.6). A new email waits for the link sent to it.
 *
 * @var array<string, string> $now first_name, middle_name, last_name, suffix, email
 */

ob_start();
?>
<form method="post" action="<?= esc(site_url('profile/identity-official'), 'attr') ?>" data-dialog-form data-profile-form class="flex flex-col gap-4">
    <?= csrf_field() ?>
    <?= view('components/form-field', ['name' => 'first_name', 'label' => 'First name', 'value' => $now['first_name'], 'required' => true, 'maxlength' => 100, 'attrs' => 'autocomplete="given-name"'], ['saveData' => false]) ?>
    <?= view('components/form-field', ['name' => 'middle_name', 'label' => 'Middle name', 'value' => $now['middle_name'], 'maxlength' => 100, 'attrs' => 'autocomplete="additional-name"'], ['saveData' => false]) ?>
    <?= view('components/form-field', ['name' => 'last_name', 'label' => 'Last name', 'value' => $now['last_name'], 'required' => true, 'maxlength' => 100, 'attrs' => 'autocomplete="family-name"'], ['saveData' => false]) ?>
    <?= view('components/form-field', ['name' => 'suffix', 'label' => 'Suffix', 'value' => $now['suffix'], 'maxlength' => 20, 'attrs' => 'autocomplete="honorific-suffix"'], ['saveData' => false]) ?>
    <?= view('components/form-field', ['name' => 'email', 'label' => 'Email', 'type' => 'email', 'value' => $now['email'], 'required' => true, 'maxlength' => 255, 'help' => "A new address gets a confirmation link. You keep signing in with the current one until you open it."], ['saveData' => false]) ?>
    <?= view('pages/profile/partials/dialogs/_actions', ['label' => 'Save'], ['saveData' => false]) ?>
</form>
<?php
$body = (string) ob_get_clean();
?>
<?= view('components/dialog', ['id' => 'profile-official', 'title' => 'Edit official details', 'body' => $body, 'size' => 'md'], ['saveData' => false]) ?>
