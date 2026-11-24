<?php
/**
 * Request a correction of an official detail (FR7). The select carries each detail's current value; dialogs.js
 * copies it into "Now" and shows the email note. A detail with an open request cannot be chosen again.
 *
 * @var array{now: array<string, string>, open: array<string, string>} $correction
 */

use App\Services\Profiles\CorrectionRequestService;

$control = 'w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-base text-[#1A1C1E] sm:text-sm pointer-coarse:min-h-11 placeholder:text-gray-400 focus:border-brand/60 focus:outline-none focus:ring-2 focus:ring-brand/40 aria-[invalid=true]:border-[#C33939] aria-[invalid=true]:ring-[#C33939]/30';
$first   = array_key_first(array_diff_key($correction['now'], $correction['open']));

ob_start();
?>
<form method="post" action="<?= esc(site_url('profile/corrections'), 'attr') ?>" data-dialog-form data-profile-form data-correction-form class="flex flex-col gap-4">
    <?= csrf_field() ?>
    <p class="text-sm text-[#3D3D3D]">Your school checks the change before it shows. You will get an email when they decide.</p>

    <div class="flex flex-col gap-1" data-field="field">
        <label class="text-xs font-medium text-gray-600" for="profile-correction-field">What needs fixing?</label>
        <select id="profile-correction-field" name="field" class="<?= $control ?>" data-correction-field aria-describedby="profile-correction-field-error">
            <?php foreach ($correction['now'] as $field => $now): ?>
                <?php $open = isset($correction['open'][$field]); ?>
                <option value="<?= esc($field, 'attr') ?>" data-now="<?= esc($now, 'attr') ?>" <?= $open ? 'disabled' : '' ?> <?= $field === $first ? 'selected' : '' ?>><?= esc(CorrectionRequestService::LABELS[$field]) ?><?= $open ? ' (waiting for a decision)' : '' ?></option>
            <?php endforeach; ?>
        </select>
        <p id="profile-correction-field-error" class="text-xs font-medium text-[#C33939]" data-field-error hidden></p>
    </div>

    <div class="flex flex-col gap-1">
        <label class="text-xs font-medium text-gray-600" for="profile-correction-now">Now</label>
        <input id="profile-correction-now" type="text" readonly value="<?= esc($first === null ? '' : $correction['now'][$first], 'attr') ?>" class="<?= $control ?> bg-gray-50 text-[#646464]" data-correction-now>
    </div>

    <?= view('components/form-field', ['name' => 'new_value', 'label' => 'Should be', 'required' => true, 'maxlength' => 255, 'attrs' => 'autocomplete="off"'], ['saveData' => false]) ?>
    <?= view('components/form-field', ['name' => 'reason', 'label' => 'Why (your school sees this)', 'type' => 'textarea', 'required' => true, 'maxlength' => CorrectionRequestService::TEXT_MAX], ['saveData' => false]) ?>

    <div data-correction-email-note <?= $first === 'email' ? '' : 'hidden' ?>>
        <?= view('components/alert', ['tone' => 'info', 'text' => "We'll email a confirmation link to the new address. Your sign-in email changes only after you open it."], ['saveData' => false]) ?>
    </div>

    <?= view('pages/profile/partials/dialogs/_actions', ['label' => 'Send request'], ['saveData' => false]) ?>
</form>
<?php
$body = (string) ob_get_clean();
?>
<?= view('components/dialog', ['id' => 'profile-correction', 'title' => 'Request a correction', 'body' => $body, 'size' => 'md'], ['saveData' => false]) ?>
