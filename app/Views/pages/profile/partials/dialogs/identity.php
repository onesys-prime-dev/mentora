<?php
/**
 * Edit name and pronouns.
 *
 * @var array<string, mixed> $edit the owner's current values
 */

$pronouns = (string) $edit['pronouns'];
$listed   = in_array($pronouns, \App\Services\Profiles\ProfileEditService::PRONOUNS, true);
$choice   = $pronouns === '' ? '' : ($listed ? $pronouns : 'custom');
$control  = 'w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-base text-[#1A1C1E] sm:text-sm pointer-coarse:min-h-11 placeholder:text-gray-400 focus:border-brand/60 focus:outline-none focus:ring-2 focus:ring-brand/40 aria-[invalid=true]:border-[#C33939] aria-[invalid=true]:ring-[#C33939]/30';
$options  = ['' => 'Not shown', 'she/her' => 'she/her', 'he/him' => 'he/him', 'they/them' => 'they/them', 'prefer not to say' => 'Prefer not to say', 'custom' => 'Custom…'];

ob_start();
?>
<form method="post" action="<?= esc(site_url('profile/identity'), 'attr') ?>" data-dialog-form data-profile-form class="flex flex-col gap-4">
    <?= csrf_field() ?>
    <?= view('components/form-field', ['name' => 'preferred_first_name', 'label' => 'Preferred first name', 'value' => $edit['preferred_first_name'], 'maxlength' => 50, 'help' => 'Shown instead of your first name. 2 to 50 letters. Leave empty to use your first name. You can change it 3 times a day.', 'attrs' => 'autocomplete="nickname"'], ['saveData' => false]) ?>

    <div class="flex flex-col gap-1" data-field="pronouns">
        <label class="text-xs font-medium text-gray-600" for="profile-pronouns-choice">Pronouns</label>
        <select id="profile-pronouns-choice" name="pronouns_choice" class="<?= $control ?>" data-pronoun-select aria-describedby="profile-pronouns-error">
            <?php foreach ($options as $value => $label): ?>
                <option value="<?= esc($value, 'attr') ?>" <?= $choice === $value ? 'selected' : '' ?>><?= esc($label) ?></option>
            <?php endforeach; ?>
        </select>
        <div data-pronoun-custom <?= $choice === 'custom' ? '' : 'hidden' ?>>
            <label class="sr-only" for="profile-pronouns-custom">Your pronouns</label>
            <input id="profile-pronouns-custom" type="text" name="pronouns_custom" maxlength="30" value="<?= esc($choice === 'custom' ? $pronouns : '', 'attr') ?>" placeholder="For example, xe/xem" class="<?= $control ?>" aria-describedby="profile-pronouns-error">
        </div>
        <p id="profile-pronouns-error" class="text-xs font-medium text-[#C33939]" data-field-error hidden></p>
    </div>

    <?= view('pages/profile/partials/dialogs/_actions', [], ['saveData' => false]) ?>
</form>
<?php
$body = (string) ob_get_clean();
?>
<?= view('components/dialog', ['id' => 'profile-edit-identity', 'title' => 'Edit name & pronouns', 'body' => $body, 'size' => 'md'], ['saveData' => false]) ?>
