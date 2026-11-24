<?php
/**
 * Edit office and consultation details (staff only).
 *
 * @var array<string, mixed> $p
 */
$info = $p['staff_info'] ?? [];
ob_start();
?>
<form method="post" action="<?= esc(site_url('profile/staff'), 'attr') ?>" data-dialog-form data-profile-form class="flex flex-col gap-4">
    <?= csrf_field() ?>
    <?= view('components/form-field', ['name' => 'office_location', 'label' => 'Office', 'value' => (string) ($info['office_location'] ?? ''), 'maxlength' => 120, 'placeholder' => 'Building and room'], ['saveData' => false]) ?>
    <?= view('components/form-field', ['name' => 'consultation_hours', 'label' => 'Consultation hours', 'type' => 'textarea', 'value' => (string) ($info['consultation_hours'] ?? ''), 'maxlength' => 200, 'placeholder' => 'Mon and Wed, 1 to 3 PM', 'attrs' => 'rows="3"'], ['saveData' => false]) ?>
    <?= view('components/form-field', ['name' => 'work_phone', 'label' => 'Work phone', 'value' => (string) ($info['work_phone'] ?? ''), 'maxlength' => 40, 'attrs' => 'inputmode="tel" autocomplete="tel"'], ['saveData' => false]) ?>
    <?= view('pages/profile/partials/dialogs/_actions', [], ['saveData' => false]) ?>
</form>
<?php
$body = (string) ob_get_clean();
?>
<?= view('components/dialog', ['id' => 'profile-edit-staff', 'title' => 'Edit office & consultation', 'body' => $body, 'size' => 'md'], ['saveData' => false]) ?>
