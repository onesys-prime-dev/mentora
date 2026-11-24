<?php
/**
 * One password input with its label, show/hide toggle (pages/login/login.js), help and error.
 * A typed password is never put back in the page.
 *
 * @var string $name
 * @var string $label
 * @var string $autocomplete current-password | new-password
 * @var string $error
 * @var string $help         optional, already-escaped HTML
 * @var string $attrs        optional extra, already-escaped attributes
 * @var string $id           optional, when the name is already used on the page
 */
$help  ??= '';
$attrs ??= '';
$id     ??= 'settings-' . str_replace('_', '-', $name);
$describe = trim(($help !== '' ? $id . '-help ' : '') . $id . '-error');
?>
<div class="flex flex-col gap-1" data-field="<?= esc($name, 'attr') ?>">
    <label class="text-xs font-medium text-gray-600" for="<?= esc($id, 'attr') ?>"><?= esc($label) ?></label>
    <div class="relative max-w-md">
        <input type="password" id="<?= esc($id, 'attr') ?>" name="<?= esc($name, 'attr') ?>" autocomplete="<?= esc($autocomplete, 'attr') ?>" required
            aria-describedby="<?= esc($describe, 'attr') ?>"<?= $error !== '' ? ' aria-invalid="true"' : '' ?> <?= $attrs ?>
            class="w-full rounded-lg border border-gray-300 bg-white py-2 pl-3 pr-11 text-base text-[#1A1C1E] sm:text-sm pointer-coarse:min-h-11 focus:border-brand/60 focus:outline-none focus:ring-2 focus:ring-brand/40 aria-[invalid=true]:border-[#C33939] aria-[invalid=true]:ring-[#C33939]/30">
        <button type="button" data-password-toggle="<?= esc($id, 'attr') ?>" aria-label="Show password" aria-pressed="false"
            class="absolute right-1 top-1/2 flex min-h-8 min-w-8 -translate-y-1/2 items-center justify-center rounded text-gray-400 hover:text-gray-600 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/50 pointer-coarse:min-h-11 pointer-coarse:min-w-11">
            <svg data-password-icon="shown" class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 12S5.25 5.25 12 5.25 21.75 12 21.75 12 18.75 18.75 12 18.75 2.25 12 2.25 12Z" />
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 15a3 3 0 1 0 0-6 3 3 0 0 0 0 6Z" />
            </svg>
            <svg data-password-icon="hidden" class="hidden h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M3.98 8.223A10.477 10.477 0 0 0 1.934 12c1.292 4.338 5.31 7.5 10.066 7.5a10.44 10.44 0 0 0 4.204-.878M6.228 6.228A10.45 10.45 0 0 1 12 4.5c4.756 0 8.774 3.162 10.066 7.5a10.522 10.522 0 0 1-4.293 5.774M6.228 6.228 3 3m3.228 3.228 3.65 3.65m7.894 7.894L21 21m-3.228-3.228-3.65-3.65m0 0a3 3 0 1 0-4.243-4.243m4.242 4.242L9.88 9.88" />
            </svg>
        </button>
    </div>
    <?php if ($help !== ''): ?>
        <div id="<?= esc($id, 'attr') ?>-help" class="text-xs text-[#646464]"><?= $help ?></div>
    <?php endif; ?>
    <p id="<?= esc($id, 'attr') ?>-error" class="text-xs font-medium text-[#C33939]" <?= $error === '' ? 'hidden' : '' ?>><?= esc($error) ?></p>
</div>
