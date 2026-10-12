<?php
/**
 * The institution's settings: profile and branding.
 *
 * @var string               $settingsTab      profile|branding
 * @var array<string, mixed> $settingsData     the institution as InstitutionProfileService::get() returns it
 * @var bool                 $settingsCanEdit
 * @var array<string, string> $settingsDefaults
 */
$d       = $settingsData;
$base    = 'institution/settings';
$primary = 'rounded-full bg-brand px-5 py-2 text-sm font-semibold text-white transition hover:bg-brand-dark focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/50';
$second  = 'rounded-full border border-brand bg-white px-5 py-2 text-sm font-semibold text-brand transition hover:bg-brand-tint focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/50';
$input   = 'w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-[#1A1C1E] focus:border-brand/60 focus:outline-none focus:ring-2 focus:ring-brand/40 disabled:bg-gray-50 disabled:text-gray-500';
$disabled = $settingsCanEdit ? '' : 'disabled';

$field = static fn (array $spec): string => view('components/form-field', $spec + ($settingsCanEdit ? [] : ['attrs' => 'disabled']), ['saveData' => false]);
?>

<?= $this->extend('layouts/app') ?>

<?= $this->section('content') ?>

<div class="flex flex-col gap-6 py-3">

    <?= view('components/page-head', [
        'title'       => 'Institution settings',
        'icon'        => 'settings',
        'description' => 'How your institution presents itself in Mentora.',
        'backUrl'     => site_url('institution/dashboard'),
        'backLabel'   => 'Back to home',
    ], ['saveData' => false]) ?>

    <?php if (! $settingsCanEdit): ?>
        <?= view('components/lock-note', ['kind' => 'read_only'], ['saveData' => false]) ?>
    <?php endif; ?>

    <?php if (session()->has('message')): ?>
        <?= view('components/alert', ['tone' => 'success', 'text' => (string) session('message')], ['saveData' => false]) ?>
    <?php endif; ?>
    <?php if (session()->has('error')): ?>
        <?= view('components/alert', ['tone' => 'danger', 'text' => (string) session('error')], ['saveData' => false]) ?>
    <?php endif; ?>

    <?= view('components/tabs', ['ariaLabel' => 'Institution settings', 'tabs' => [
        ['label' => 'Profile', 'href' => site_url($base), 'active' => $settingsTab === 'profile'],
        ['label' => 'Branding', 'href' => site_url($base . '/branding'), 'active' => $settingsTab === 'branding'],
    ]], ['saveData' => false]) ?>

    <?php if ($settingsTab === 'profile'): ?>

        <form method="post" action="<?= esc(site_url($base)) ?>" data-dialog-form class="flex max-w-2xl flex-col gap-4 rounded-2xl bg-white p-6 shadow-sm">
            <?= csrf_field() ?>

            <?= $field(['name' => 'name', 'label' => 'Display name', 'value' => (string) $d['name'], 'required' => true, 'maxlength' => 150, 'help' => 'Shown to your students, instructors and parents.']) ?>

            <div class="flex flex-col gap-1">
                <span class="text-xs font-medium text-gray-600">Code</span>
                <p class="rounded-lg bg-gray-50 px-3 py-2 text-sm font-mono text-[#1A1C1E]" data-institution-code><?= esc((string) $d['code']) ?></p>
                <p class="text-xs text-[#646464]">The code identifies your institution and cannot be changed.</p>
            </div>

            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <?= $field(['name' => 'contact_email', 'label' => 'Contact email', 'type' => 'email', 'value' => (string) ($d['contact_email'] ?? ''), 'maxlength' => 254, 'help' => 'Where people can reach your institution.']) ?>
                <?= $field(['name' => 'contact_phone', 'label' => 'Contact phone', 'value' => (string) ($d['contact_phone'] ?? ''), 'maxlength' => 40]) ?>
            </div>

            <?= $field(['name' => 'address', 'label' => 'Address', 'type' => 'textarea', 'value' => (string) ($d['address'] ?? ''), 'maxlength' => 500]) ?>

            <p class="text-xs font-medium text-[#C33939]" data-form-error hidden></p>

            <?php if ($settingsCanEdit): ?>
                <div class="flex justify-end"><button type="submit" class="<?= $primary ?>">Save profile</button></div>
            <?php endif; ?>
        </form>

    <?php else: ?>

        <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">

            <div class="flex flex-col gap-6">

                <section class="flex flex-col gap-4 rounded-2xl bg-white p-6 shadow-sm" aria-labelledby="logo-heading">
                    <h2 id="logo-heading" class="text-sm font-bold text-brand">Logo</h2>

                    <?php if ($d['logo_file_id'] !== null): ?>
                        <div class="flex items-center gap-4">
                            <img class="h-14 w-auto max-w-[12rem] rounded border border-gray-200 bg-white object-contain p-1" src="<?= esc(site_url('files/' . (int) $d['logo_file_id'])) ?>" alt="Current logo of <?= esc((string) $d['name']) ?>" data-current-logo>
                            <?php if ($settingsCanEdit): ?>
                                <form method="post" action="<?= esc(site_url($base . '/logo/remove')) ?>" data-dialog-form>
                                    <?= csrf_field() ?>
                                    <button type="submit" class="text-sm font-semibold text-[#C33939] hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#C33939]/50 rounded">Remove logo</button>
                                </form>
                            <?php endif; ?>
                        </div>
                    <?php else: ?>
                        <p class="text-sm text-[#646464]" data-no-logo>No logo yet. Mentora's logo is shown on its own.</p>
                    <?php endif; ?>

                    <form method="post" action="<?= esc(site_url($base . '/logo')) ?>" enctype="multipart/form-data" data-dialog-form class="flex flex-col gap-3">
                        <?= csrf_field() ?>
                        <div class="flex flex-col gap-1" data-field="logo">
                            <label class="text-xs font-medium text-gray-600" for="logo-file">Choose an image</label>
                            <input id="logo-file" name="logo" type="file" accept="image/png,image/jpeg,image/webp" aria-describedby="logo-help logo-error" <?= $disabled ?>
                                   class="block w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm file:mr-3 file:rounded-full file:border-0 file:bg-brand-tint file:px-4 file:py-1.5 file:text-sm file:font-semibold file:text-brand">
                            <p id="logo-help" class="text-xs text-[#646464]">PNG, JPEG or WebP, up to 1 MB and 2000 pixels a side. It counts towards your storage.</p>
                            <p id="logo-error" class="text-xs font-medium text-[#C33939]" data-field-error hidden></p>
                        </div>
                        <p class="text-xs font-medium text-[#C33939]" data-form-error hidden></p>
                        <?php if ($settingsCanEdit): ?>
                            <div><button type="submit" class="<?= $second ?>">Upload logo</button></div>
                        <?php endif; ?>
                    </form>
                </section>

                <section class="rounded-2xl bg-white p-6 shadow-sm" aria-labelledby="colours-heading">
                    <h2 id="colours-heading" class="text-sm font-bold text-brand">Colours</h2>

                    <form method="post" action="<?= esc(site_url($base . '/branding')) ?>" data-dialog-form data-brand-form class="mt-4 flex flex-col gap-4">
                        <?= csrf_field() ?>

                        <?php foreach (['primary' => ['Primary colour', 'Buttons, links and headings.'], 'accent' => ['Accent colour', 'Highlights and positive states.']] as $role => [$label, $help]): ?>
                            <div class="flex flex-col gap-1" data-field="<?= esc($role) ?>">
                                <label class="text-xs font-medium text-gray-600" for="brand-<?= esc($role) ?>"><?= esc($label) ?></label>
                                <div class="flex items-center gap-3">
                                    <input type="color" value="<?= esc(strtolower((string) $d[$role])) ?>" data-brand-swatch="<?= esc($role) ?>" aria-label="<?= esc($label) ?> picker"
                                           class="h-10 w-12 shrink-0 cursor-pointer rounded border border-gray-300 bg-white p-1" <?= $disabled ?>>
                                    <input id="brand-<?= esc($role) ?>" name="<?= esc($role) ?>" type="text" value="<?= esc((string) $d[$role]) ?>" maxlength="7" autocomplete="off" spellcheck="false"
                                           data-brand-input="<?= esc($role) ?>" data-default="<?= esc($settingsDefaults[$role]) ?>" aria-describedby="brand-<?= esc($role) ?>-note brand-<?= esc($role) ?>-error"
                                           class="<?= $input ?> max-w-[10rem] font-mono" <?= $disabled ?>>
                                </div>
                                <p class="text-xs text-[#646464]"><?= esc($help) ?></p>
                                <p id="brand-<?= esc($role) ?>-note" class="text-xs font-semibold" data-brand-note="<?= esc($role) ?>" aria-live="polite"></p>
                                <p id="brand-<?= esc($role) ?>-error" class="text-xs font-medium text-[#C33939]" data-field-error hidden></p>
                            </div>
                        <?php endforeach; ?>

                        <p class="text-xs font-medium text-[#C33939]" data-form-error hidden></p>

                        <?php if ($settingsCanEdit): ?>
                            <div class="flex flex-wrap items-center justify-between gap-3">
                                <button type="button" class="text-sm font-semibold text-brand hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/50 rounded" data-brand-default>Use the Mentora colours</button>
                                <button type="submit" class="<?= $primary ?>">Save colours</button>
                            </div>
                        <?php endif; ?>
                    </form>

                    <?php if ($settingsCanEdit && $d['custom']): ?>
                        <form method="post" action="<?= esc(site_url($base . '/branding/reset')) ?>" data-dialog-form class="mt-3 border-t border-gray-100 pt-3">
                            <?= csrf_field() ?>
                            <button type="submit" class="text-sm font-semibold text-[#C33939] hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#C33939]/50 rounded">Reset to the Mentora colours</button>
                        </form>
                    <?php endif; ?>
                </section>

            </div>

            <section class="flex flex-col gap-4 rounded-2xl bg-white p-6 shadow-sm" aria-labelledby="preview-heading">
                <h2 id="preview-heading" class="text-sm font-bold text-brand">Preview</h2>
                <p class="text-xs text-[#646464]">This is how buttons, links and badges will look. Nothing changes until you save.</p>

                <div class="flex flex-col gap-4 rounded-xl border border-gray-200 bg-white p-4" data-brand-preview>
                    <div class="flex items-center gap-3 rounded-lg bg-brand-tint px-4 py-3">
                        <?php if ($d['logo_file_id'] !== null): ?>
                            <img class="h-8 w-auto max-w-[8rem] object-contain" src="<?= esc(site_url('files/' . (int) $d['logo_file_id'])) ?>" alt="">
                        <?php endif; ?>
                        <span class="text-base font-bold text-brand"><?= esc((string) $d['name']) ?></span>
                    </div>

                    <div class="flex flex-wrap items-center gap-3">
                        <button type="button" class="rounded-full bg-brand px-5 py-2 text-sm font-semibold text-white transition hover:bg-brand-dark">Primary button</button>
                        <button type="button" class="rounded-full border border-brand bg-white px-5 py-2 text-sm font-semibold text-brand transition hover:bg-brand-tint">Secondary</button>
                        <a href="#preview-heading" class="text-sm font-semibold text-brand underline">A link</a>
                    </div>

                    <div class="flex flex-wrap items-center gap-3">
                        <span class="rounded-full bg-brand/10 px-3 py-1 text-xs font-bold text-brand">Selected</span>
                        <span class="rounded-full bg-accent px-3 py-1 text-xs font-bold text-white">Accent</span>
                    </div>
                </div>
            </section>

        </div>

    <?php endif; ?>

</div>

<?= $this->endSection() ?>
