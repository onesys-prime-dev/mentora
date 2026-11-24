<?php
/**
 * Edit the About text.
 *
 * @var array<string, mixed> $p
 */
ob_start();
?>
<form method="post" action="<?= esc(site_url('profile/about'), 'attr') ?>" data-dialog-form data-profile-form class="flex flex-col gap-4">
    <?= csrf_field() ?>
    <?= view('components/form-field', ['name' => 'bio', 'label' => 'About', 'type' => 'textarea', 'value' => (string) ($p['bio'] ?? ''), 'maxlength' => 300, 'help' => 'Plain text, up to 300 characters. People in your school see this.', 'attrs' => 'rows="5"'], ['saveData' => false]) ?>
    <?= view('pages/profile/partials/dialogs/_actions', [], ['saveData' => false]) ?>
</form>
<?php
$body = (string) ob_get_clean();
?>
<?= view('components/dialog', ['id' => 'profile-edit-about', 'title' => 'Edit about', 'body' => $body, 'size' => 'md'], ['saveData' => false]) ?>
