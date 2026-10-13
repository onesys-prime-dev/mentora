<?php

/*
|--------------------------------------------------------------------------
| Form field — label, control, help and error, tied together
|--------------------------------------------------------------------------
| Params: name (required), label (required), type (text|email|password|date|
| number|search|textarea|select|checkbox), value, options (value => label,
| for select), help, error, required, placeholder, maxlength, attrs (extra,
| already-escaped attributes), span (grid class such as "sm:col-span-2").
|
| The wrapper carries data-field="<name>" and an empty [data-field-error], so
| shared/dialog.js can show a server-side error in place (a 422 answer).
|--------------------------------------------------------------------------
*/

$type        = $type ?? 'text';
$value       = $value ?? '';
$options     = $options ?? [];
$help        = $help ?? '';
$error       = $error ?? '';
$required    = $required ?? false;
$placeholder = $placeholder ?? '';
$maxlength   = $maxlength ?? null;
$attrs       = $attrs ?? '';
$span        = $span ?? '';

// A page can hold the same field twice (an add and an edit dialog): each needs its own id,
// or the label of the second would point at the first.
$GLOBALS['mentoraFormFieldSeq'] = ((int) ($GLOBALS['mentoraFormFieldSeq'] ?? 0)) + 1;

$id       = 'f-' . preg_replace('/[^a-z0-9_-]/i', '-', $name) . '-' . $GLOBALS['mentoraFormFieldSeq'];
$describe = trim(($help !== '' ? $id . '-help ' : '') . $id . '-error');

$control = 'w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-[#1A1C1E] placeholder:text-gray-400 '
    . 'focus:border-brand/60 focus:outline-none focus:ring-2 focus:ring-brand/40 disabled:bg-gray-50 disabled:text-gray-500 '
    . 'aria-[invalid=true]:border-[#C33939] aria-[invalid=true]:ring-[#C33939]/30';

$common = 'id="' . esc($id) . '" name="' . esc($name) . '" aria-describedby="' . esc($describe) . '"'
    . ($required ? ' required' : '')
    . ($error !== '' ? ' aria-invalid="true"' : '')
    . ($placeholder !== '' ? ' placeholder="' . esc($placeholder) . '"' : '')
    . ($maxlength !== null ? ' maxlength="' . (int) $maxlength . '"' : '')
    . ($attrs !== '' ? ' ' . $attrs : '');
?>

<div class="flex flex-col gap-1 <?= esc($span) ?>" data-field="<?= esc($name) ?>">

    <?php if ($type === 'checkbox'): ?>

        <label class="flex items-center gap-2 text-sm text-[#1A1C1E]" for="<?= esc($id) ?>">
            <input type="checkbox" value="1" class="h-4 w-4 rounded border-gray-300 text-brand focus:ring-brand/40" <?= $common ?> <?= $value ? 'checked' : '' ?>>
            <?= esc($label) ?>
        </label>

    <?php else: ?>

        <label class="text-xs font-medium text-gray-600" for="<?= esc($id) ?>">
            <?= esc($label) ?><?php if ($required): ?> <span class="text-[#C33939]" aria-hidden="true">*</span><?php endif; ?>
        </label>

        <?php if ($type === 'textarea'): ?>
            <textarea rows="3" class="<?= $control ?>" <?= $common ?>><?= esc((string) $value) ?></textarea>
        <?php elseif ($type === 'select'): ?>
            <select class="<?= $control ?>" <?= $common ?>>
                <?php foreach ($options as $optionValue => $optionLabel): ?>
                    <option value="<?= esc((string) $optionValue) ?>" <?= (string) $value === (string) $optionValue ? 'selected' : '' ?>><?= esc((string) $optionLabel) ?></option>
                <?php endforeach; ?>
            </select>
        <?php else: ?>
            <input type="<?= esc($type) ?>" value="<?= esc((string) $value) ?>" class="<?= $control ?>" <?= $common ?>>
        <?php endif; ?>

    <?php endif; ?>

    <?php if ($help !== ''): ?>
        <p id="<?= esc($id) ?>-help" class="text-xs text-[#646464]"><?= esc($help) ?></p>
    <?php endif; ?>

    <p id="<?= esc($id) ?>-error" class="text-xs font-medium text-[#C33939]" data-field-error <?= $error === '' ? 'hidden' : '' ?>><?= esc($error) ?></p>

</div>
