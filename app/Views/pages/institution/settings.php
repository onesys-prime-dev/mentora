<?php
/**
 * The institution's settings: profile and branding.
 *
 * Every tab fills the card: its sections sit in a grid that gains columns as the card widens (the
 * main sidebar collapsing, a bigger screen) and loses them as it narrows. A form's Save sits in a bar
 * pinned to the bottom of the card, so it stays in reach however long the page is.
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
$danger  = 'rounded text-sm font-semibold text-[#C33939] hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#C33939]/50';
$disabled = $settingsCanEdit ? '' : 'disabled';

// The layout pieces every tab shares.
$sections = 'grid grid-cols-[repeat(auto-fit,minmax(min(100%,20rem),1fr))] gap-x-12 gap-y-8';
$subgrid  = 'grid grid-cols-[repeat(auto-fit,minmax(min(100%,12rem),1fr))] gap-x-5 gap-y-4';
$section  = 'flex min-w-0 flex-col gap-4';
$heading  = 'border-b border-gray-200 pb-2 text-xs font-bold uppercase tracking-wider text-brand';
$formCls  = 'flex flex-col gap-6';
// Below lg the whole panel scrolls, so the bar sticks to its bottom; from lg up the form content scrolls and the bar just sits under it.
// The panel has no bottom padding on a page with a bar, so the bar's own padding is the same above and below Save and the card ends at it.
$bar      = 'sticky bottom-0 z-10 -mx-5 flex flex-wrap items-center justify-between gap-3 rounded-b-3xl border-t border-gray-200 bg-white px-5 py-3 sm:-mx-8 sm:px-8';
$hasBar   = $settingsCanEdit && $settingsTab !== 'branding';

$field = static fn (array $spec): string => view('components/form-field', $spec + ($settingsCanEdit ? [] : ['attrs' => 'disabled']), ['saveData' => false]);
?>

<?= $this->extend('layouts/app') ?>

<?= $this->section('content') ?>

<div class="py-3">

<?php // The way back stays on the background, above the panel, not in the box. ?>
<?= view('components/back-link', ['backUrl' => site_url('institution/dashboard'), 'backLabel' => 'Back to home'], ['saveData' => false]) ?>

<div class="flex flex-col gap-6 rounded-3xl bg-white shadow-sm <?= $hasBar ? 'px-5 pt-5 sm:px-8 sm:pt-8' : 'p-5 sm:p-8' ?>" data-page-panel>

    <?= view('components/page-head', [
        'title'       => 'Institution settings',
        'icon'        => 'settings',
        'description' => 'How your institution presents itself in Mentora.',
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
        ['label' => 'Academic', 'href' => site_url($base . '/academic'), 'active' => $settingsTab === 'academic'],
        ['label' => 'Announcement', 'href' => site_url($base . '/announcement'), 'active' => $settingsTab === 'announcement'],
        ['label' => 'Security', 'href' => site_url($base . '/security'), 'active' => $settingsTab === 'security'],
        ['label' => 'Parents', 'href' => site_url($base . '/parents'), 'active' => $settingsTab === 'parents'],
    ]], ['saveData' => false]) ?>

    <?php if ($settingsTab === 'profile'): ?>

        <form method="post" action="<?= esc(site_url($base)) ?>" data-dialog-form data-panel-form class="<?= $formCls ?>">
            <?= csrf_field() ?>

            <div class="<?= $sections ?>" data-panel-scroll>
                <section class="<?= $section ?>" aria-labelledby="identity-heading">
                    <h2 id="identity-heading" class="<?= $heading ?>">Identity</h2>

                    <?= $field(['name' => 'name', 'label' => 'Display name', 'value' => (string) $d['name'], 'required' => true, 'maxlength' => 150, 'help' => 'Shown to your students, instructors and parents.']) ?>

                    <div class="flex flex-col gap-1">
                        <span class="text-xs font-medium text-gray-600">Code</span>
                        <p class="w-fit rounded-lg bg-brand-tint px-3 py-2 text-sm font-mono text-[#1A1C1E]" data-institution-code><?= esc((string) $d['code']) ?></p>
                        <p class="text-xs text-[#646464]">The code identifies your institution and cannot be changed.</p>
                    </div>
                </section>

                <section class="<?= $section ?>" aria-labelledby="contact-heading">
                    <h2 id="contact-heading" class="<?= $heading ?>">Contact</h2>

                    <div class="<?= $subgrid ?>">
                        <?= $field(['name' => 'contact_email', 'label' => 'Contact email', 'type' => 'email', 'value' => (string) ($d['contact_email'] ?? ''), 'maxlength' => 254, 'help' => 'Where people can reach your institution.']) ?>
                        <?= $field(['name' => 'contact_phone', 'label' => 'Contact phone', 'value' => (string) ($d['contact_phone'] ?? ''), 'maxlength' => 40]) ?>
                    </div>

                    <?= $field(['name' => 'address', 'label' => 'Address', 'type' => 'textarea', 'value' => (string) ($d['address'] ?? ''), 'maxlength' => 500]) ?>
                </section>
            </div>

            <p class="text-xs font-medium text-[#C33939]" data-form-error hidden></p>

            <?php if ($settingsCanEdit): ?>
                <div class="<?= $bar ?>">
                    <p class="text-xs text-[#646464]">Shown to students, instructors and parents.</p>
                    <button type="submit" class="<?= $primary ?>">Save profile</button>
                </div>
            <?php endif; ?>
        </form>

    <?php elseif ($settingsTab === 'announcement'): ?>

        <?php $n = $settingsAnnouncement; ?>

        <form method="post" action="<?= esc(site_url($base . '/announcement')) ?>" data-dialog-form data-panel-form class="<?= $formCls ?>" data-announcement-form>
            <?= csrf_field() ?>

            <div class="<?= $sections ?>" data-panel-scroll>
                <section class="<?= $section ?>" aria-labelledby="announcement-heading">
                    <h2 id="announcement-heading" class="<?= $heading ?>">Message</h2>

                    <p class="text-sm text-[#3D3D3D]">One line for everyone in your institution, shown at the top of their pages under the Mentora announcement. People can hide it for themselves.</p>

                    <?= $field(['name' => 'text', 'label' => 'Announcement', 'type' => 'textarea', 'value' => (string) $n['text'], 'maxlength' => 300, 'help' => 'Plain text, up to 300 characters. Leave it empty and save to take it down.']) ?>
                    <?= $field(['name' => 'until', 'label' => 'Show until (optional)', 'type' => 'date', 'value' => (string) $n['until'], 'help' => 'The last day it is shown. Empty keeps it up until you remove it.']) ?>
                </section>

                <section class="<?= $section ?>" aria-labelledby="announcement-preview">
                    <h2 id="announcement-preview" class="<?= $heading ?>">As your people will see it</h2>

                    <?php if ($n['text'] === ''): ?>
                        <p class="text-sm text-[#646464]" data-no-announcement>No announcement is set.</p>
                    <?php else: ?>
                        <div class="flex items-center justify-between gap-3 rounded-lg border border-brand/20 bg-brand-tint px-4 py-2 text-sm text-brand-dark" data-announcement-preview>
                            <span><?= esc((string) $n['text']) ?></span>
                            <span class="rounded px-2 py-0.5 text-xs font-semibold">Dismiss</span>
                        </div>
                        <p class="text-xs <?= $n['showing'] ? 'text-[#2E7D4F]' : 'text-[#946200]' ?>" data-announcement-state>
                            <?php if ($n['showing']): ?>
                                It is showing now<?= $n['until'] !== '' ? ', until ' . esc(date('j M Y', strtotime((string) $n['until']))) : '' ?>.
                            <?php else: ?>
                                It ended on <?= esc(date('j M Y', strtotime((string) $n['until']))) ?> and is no longer shown.
                            <?php endif; ?>
                        </p>
                    <?php endif; ?>

                    <?php if ($settingsCanEdit && $n['text'] !== ''): ?>
                        <?php // Saving it empty is what takes it down: the script clears the text and submits this form. ?>
                        <button type="button" class="self-start <?= $danger ?>" data-announcement-clear>Remove announcement</button>
                    <?php endif; ?>
                </section>
            </div>

            <p class="text-xs font-medium text-[#C33939]" data-form-error hidden></p>

            <?php if ($settingsCanEdit): ?>
                <div class="<?= $bar ?>">
                    <p class="text-xs text-[#646464]">People can hide it for themselves.</p>
                    <button type="submit" class="<?= $primary ?>">Save announcement</button>
                </div>
            <?php endif; ?>
        </form>

    <?php elseif ($settingsTab === 'parents'): ?>

        <?php $pp = $settingsParents; ?>

        <form method="post" action="<?= esc(site_url($base . '/parents')) ?>" data-dialog-form data-panel-form class="<?= $formCls ?>" data-parent-portal-form>
            <?= csrf_field() ?>

            <div class="<?= $sections ?>" data-panel-scroll>
                <fieldset class="<?= $section ?>" <?= $disabled ?>>
                    <legend class="<?= $heading ?> w-full">Parent portal</legend>
                    <p class="text-sm text-[#3D3D3D]">Lets linked parents see their child's courses and grades, read-only. Switching it off hides it from every parent at once; nothing is deleted.</p>

                    <div class="flex flex-wrap gap-4">
                        <label class="flex items-center gap-2 text-sm text-[#1A1C1E]">
                            <input type="radio" name="enabled" value="1" class="h-4 w-4 border-gray-300 text-brand focus:ring-brand/40" <?= $pp['own'] ? 'checked' : '' ?> <?= $disabled ?>>
                            On
                        </label>
                        <label class="flex items-center gap-2 text-sm text-[#1A1C1E]">
                            <input type="radio" name="enabled" value="0" class="h-4 w-4 border-gray-300 text-brand focus:ring-brand/40" <?= $pp['own'] ? '' : 'checked' ?> <?= $disabled ?>>
                            Off
                        </label>
                    </div>
                </fieldset>
            </div>

            <?php if (! $pp['platform']): ?>
                <?= view('components/lock-note', ['kind' => 'platform', 'text' => 'Switched off by Mentora for your institution. You cannot turn it on.'], ['saveData' => false]) ?>
            <?php endif; ?>

            <p class="text-xs font-medium text-[#C33939]" data-form-error hidden></p>

            <?php if ($settingsCanEdit): ?>
                <div class="<?= $bar ?>">
                    <p class="text-xs text-[#646464]">Switching it off hides it from every parent. Nothing is deleted.</p>
                    <button type="submit" class="<?= $primary ?>">Save parent portal</button>
                </div>
            <?php endif; ?>
        </form>

    <?php elseif ($settingsTab === 'security'): ?>

        <?php
        $s      = $settingsSecurity;
        $rows   = [
            'passwordMinLength'     => ['Shortest password', 'characters', 'More than the platform asks for.'],
            'sessionTimeoutMinutes' => ['Sign out after inactivity', 'minutes', 'Fewer minutes than the platform.'],
            'lockoutAttempts'       => ['Failed sign-ins before a lockout', 'attempts', 'Fewer attempts than the platform.'],
            'lockoutMinutes'        => ['Lockout length', 'minutes', 'Longer than the platform.'],
        ];
        $roleNames = ['institutional_admin' => 'Institutional administrators', 'dean' => 'Deans', 'chairperson' => 'Chairpersons', 'instructor' => 'Instructors', 'student' => 'Students', 'parent' => 'Parents'];
        $hasOwn    = $s['ownRoles'] !== [] || count(array_filter($s['own'], static fn ($v): bool => $v !== null)) > 0;
        $canReset  = $settingsCanEdit && $hasOwn;
        ?>

        <form method="post" action="<?= esc(site_url($base . '/security')) ?>" data-dialog-form data-panel-form class="<?= $formCls ?>" data-security-form>
            <?= csrf_field() ?>

            <div class="flex flex-col gap-6" data-panel-scroll>

            <p class="max-w-3xl text-sm text-[#3D3D3D]">Your institution can be <strong>stricter</strong> than the platform, never looser. Leave a box empty to follow the platform. Changing these asks for your password.</p>

            <section class="<?= $section ?>" aria-labelledby="rules-heading">
                <h2 id="rules-heading" class="<?= $heading ?>">Sign-in rules</h2>

                <div class="<?= $subgrid ?>">
                    <?php foreach ($rows as $key => [$label, $unit, $hint]): ?>
                        <div class="flex flex-col gap-1" data-field="<?= esc($key) ?>">
                            <label for="sec-<?= esc($key) ?>" class="text-xs font-medium text-gray-600"><?= esc($label) ?></label>
                            <div class="flex items-center gap-2">
                                <input id="sec-<?= esc($key) ?>" name="<?= esc($key) ?>" type="number" inputmode="numeric" min="0" value="<?= $s['own'][$key] === null ? '' : esc((string) $s['own'][$key]) ?>" placeholder="Platform"
                                       class="<?= $input ?> max-w-[8rem]" <?= $disabled ?>>
                                <span class="text-sm text-[#646464]"><?= esc($unit) ?></span>
                            </div>
                            <p class="text-xs text-[#646464]"><?= esc($hint) ?></p>
                            <p class="text-xs text-[#646464]">
                                Platform: <span data-platform-value><?= esc((string) $s['platform'][$key]) ?> <?= esc($unit) ?><?= $key === 'lockoutAttempts' && (int) $s['platform'][$key] === 0 ? ' (never)' : '' ?></span>
                                · <span class="font-semibold text-brand">In force: <span data-in-force><?= esc((string) $s['effective'][$key]) ?> <?= esc($unit) ?></span></span>
                            </p>
                            <p class="text-xs font-medium text-[#C33939]" data-field-error hidden></p>
                        </div>
                    <?php endforeach; ?>
                </div>
            </section>

            <fieldset class="<?= $section ?>" data-field="twoFactorRoles">
                <legend class="<?= $heading ?> w-full">Emailed sign-in code</legend>
                <p class="text-xs text-[#646464]">Roles that must confirm a code sent to their email when they sign in. The platform's roles stay on; you can add more.</p>

                <div class="grid grid-cols-[repeat(auto-fit,minmax(min(100%,16rem),1fr))] gap-x-8 gap-y-2">
                    <?php foreach ($roleNames as $role => $name): ?>
                        <?php $byPlatform = in_array($role, (array) $s['platform']['twoFactorRoles'], true); ?>
                        <label class="flex items-center gap-2 text-sm text-[#1A1C1E]">
                            <input type="checkbox" name="twoFactorRoles[]" value="<?= esc($role) ?>" class="h-4 w-4 rounded border-gray-300 text-brand focus:ring-brand/40"
                                   <?= $byPlatform || in_array($role, $s['ownRoles'], true) ? 'checked' : '' ?> <?= $byPlatform || ! $settingsCanEdit ? 'disabled' : '' ?>>
                            <?= esc($name) ?><?= $byPlatform ? ' <span class="text-xs text-[#646464]">(required by the platform)</span>' : '' ?>
                        </label>
                    <?php endforeach; ?>
                </div>

                <p class="text-xs font-medium text-[#C33939]" data-field-error hidden></p>
            </fieldset>

            </div>

            <p class="text-xs font-medium text-[#C33939]" data-form-error hidden></p>

            <?php if ($settingsCanEdit): ?>
                <div class="<?= $bar ?>">
                    <?php // The reset is its own form (below); `form` points this button at it from inside the bar. ?>
                    <?php if ($canReset): ?>
                        <button type="submit" form="security-reset" class="<?= $danger ?>">Go back to the platform values</button>
                    <?php else: ?>
                        <span></span>
                    <?php endif; ?>
                    <button type="submit" class="<?= $primary ?>">Save security settings</button>
                </div>
            <?php endif; ?>
        </form>

        <?php if ($canReset): ?>
            <form id="security-reset" method="post" action="<?= esc(site_url($base . '/security/reset')) ?>" data-dialog-form>
                <?= csrf_field() ?>
            </form>
        <?php endif; ?>

    <?php elseif ($settingsTab === 'academic'): ?>

        <?php
        $a       = $settingsAcademic;
        $source  = static fn (bool $own): string => $own ? 'Set by your institution.' : 'Using the platform value.';
        $weights = array_pad($a['categories'], 6, ['name' => '', 'weight' => '']);
        $off     = $settingsCanEdit ? '' : ' disabled';
        $canReset = $settingsCanEdit && ($a['passingFromInstitution'] || $a['scaleFromInstitution'] || $a['categoriesFromInstitution'] || $a['inactiveFromInstitution'] || $a['behindFromInstitution'] || $a['overdueFromInstitution']);

        $scaleOptions = [];

        foreach ($a['scales'] as $scale) {
            $scaleOptions[(string) $scale['id']] = $scale['name'];
        }
        ?>

        <form method="post" action="<?= esc(site_url($base . '/academic')) ?>" data-dialog-form data-panel-form class="<?= $formCls ?>" data-academic-form>
            <?= csrf_field() ?>

            <div class="<?= $sections ?>" data-panel-scroll>
                <section class="<?= $section ?>" aria-labelledby="new-offerings">
                    <div class="border-b border-gray-200 pb-2">
                        <h2 id="new-offerings" class="text-xs font-bold uppercase tracking-wider text-brand">New offerings start with</h2>
                        <p class="mt-1 text-xs text-[#646464]">Offerings that already have a grading scheme keep it.</p>
                    </div>

                    <div class="<?= $subgrid ?>">
                        <?= $field(['name' => 'passing_percentage', 'label' => 'Passing mark (%)', 'type' => 'number', 'value' => (string) $a['passing'], 'attrs' => 'min="1" max="100" step="0.01" inputmode="decimal"' . $off, 'help' => $source($a['passingFromInstitution'])]) ?>
                        <?= $field(['name' => 'grade_scale_id', 'label' => 'Grade scale', 'type' => 'select', 'options' => ['' => 'Platform default'] + $scaleOptions, 'value' => $a['scaleFromInstitution'] ? (string) $a['scaleId'] : '', 'attrs' => trim($off), 'help' => $source($a['scaleFromInstitution'])]) ?>
                    </div>
                </section>

                <section class="<?= $section ?>" aria-labelledby="categories-heading" data-field="categories">
                    <div class="border-b border-gray-200 pb-2">
                        <h2 id="categories-heading" class="text-xs font-bold uppercase tracking-wider text-brand">Default grading categories</h2>
                        <p class="mt-1 text-xs text-[#646464]"><?= $a['categoriesFromInstitution'] ? 'Set by your institution.' : 'Using the platform categories.' ?> The weights add up to 100%. Leave every row empty to use the platform categories.</p>
                    </div>

                    <div class="grid grid-cols-[1fr_7rem] gap-2" role="group" aria-label="Categories and their weights">
                        <?php foreach ($weights as $i => $row): ?>
                            <input type="text" name="category_name[]" value="<?= esc((string) $row['name']) ?>" maxlength="100" aria-label="Category <?= $i + 1 ?> name" placeholder="Category name"
                                   class="<?= $input ?>" <?= $disabled ?>>
                            <input type="text" name="category_weight[]" value="<?= $row['weight'] === '' ? '' : esc((string) $row['weight']) ?>" inputmode="decimal" aria-label="Category <?= $i + 1 ?> weight" placeholder="%"
                                   class="<?= $input ?>" <?= $disabled ?>>
                        <?php endforeach; ?>
                    </div>
                    <p class="text-xs font-medium text-[#C33939]" data-field-error hidden></p>
                </section>

                <section class="<?= $section ?> col-span-full" aria-labelledby="thresholds">
                    <div class="border-b border-gray-200 pb-2">
                        <h2 id="thresholds" class="text-xs font-bold uppercase tracking-wider text-brand">What counts as needing attention</h2>
                        <p class="mt-1 text-xs text-[#646464]">Used by reports, by the chairperson, instructor and dean pages, so everyone reads the same numbers.</p>
                    </div>

                    <div class="grid grid-cols-[repeat(auto-fit,minmax(min(100%,14rem),1fr))] gap-x-8 gap-y-4">
                        <?= $field(['name' => 'inactive_days', 'label' => 'No recent activity (days)', 'type' => 'number', 'value' => (string) $a['inactiveDays'], 'attrs' => 'min="1" max="365" inputmode="numeric"' . $off, 'help' => 'A student or course silent for this long. ' . $source($a['inactiveFromInstitution'])]) ?>
                        <?= $field(['name' => 'behind_below_percent', 'label' => 'Behind below (%)', 'type' => 'number', 'value' => (string) $a['behindBelow'], 'attrs' => 'min="1" max="100" inputmode="numeric"' . $off, 'help' => 'Progress through the published items. ' . $source($a['behindFromInstitution'])]) ?>
                        <?= $field(['name' => 'overdue_grading_days', 'label' => 'Overdue grading (days)', 'type' => 'number', 'value' => (string) $a['overdueGradingDays'], 'attrs' => 'min="1" max="90" inputmode="numeric"' . $off, 'help' => 'Work waiting this long to be graded. ' . $source($a['overdueFromInstitution'])]) ?>
                        <?= $field(['name' => 'high_load_units', 'label' => 'High teaching load (units)', 'type' => 'number', 'value' => (string) $a['highLoadUnits'], 'attrs' => 'min="1" max="100" inputmode="numeric"' . $off, 'help' => 'An instructor teaching this many units in a term is marked on the dean Teaching load page. ' . $source($a['highLoadFromInstitution'])]) ?>
                    </div>
                </section>
            </div>

            <p class="text-xs font-medium text-[#C33939]" data-form-error hidden></p>

            <?php if ($settingsCanEdit): ?>
                <div class="<?= $bar ?>">
                    <?php // The reset is its own form (below); `form` points this button at it from inside the bar. ?>
                    <?php if ($canReset): ?>
                        <button type="submit" form="academic-reset" class="<?= $danger ?>">Go back to the platform values</button>
                    <?php else: ?>
                        <span></span>
                    <?php endif; ?>
                    <button type="submit" class="<?= $primary ?>">Save academic defaults</button>
                </div>
            <?php endif; ?>
        </form>

        <?php if ($canReset): ?>
            <form id="academic-reset" method="post" action="<?= esc(site_url($base . '/academic/reset')) ?>" data-dialog-form>
                <?= csrf_field() ?>
            </form>
        <?php endif; ?>

    <?php else: ?>

        <div class="<?= $sections ?>" data-panel-scroll>

            <section class="<?= $section ?>" aria-labelledby="logo-heading">
                <h2 id="logo-heading" class="<?= $heading ?>">Logo</h2>

                <?php if ($d['logo_file_id'] !== null): ?>
                    <div class="flex items-center gap-4">
                        <img class="h-14 w-auto max-w-[12rem] rounded border border-gray-200 bg-white object-contain p-1" src="<?= esc(site_url('files/' . (int) $d['logo_file_id'])) ?>" alt="Current logo of <?= esc((string) $d['name']) ?>" data-current-logo>
                        <?php if ($settingsCanEdit): ?>
                            <form method="post" action="<?= esc(site_url($base . '/logo/remove')) ?>" data-dialog-form>
                                <?= csrf_field() ?>
                                <button type="submit" class="<?= $danger ?>">Remove logo</button>
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

            <section class="<?= $section ?>" aria-labelledby="colours-heading">
                <h2 id="colours-heading" class="<?= $heading ?>">Colours</h2>

                <form method="post" action="<?= esc(site_url($base . '/branding')) ?>" data-dialog-form data-brand-form class="flex flex-col gap-4">
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
                            <button type="button" class="rounded text-sm font-semibold text-brand hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/50" data-brand-default>Use the Mentora colours</button>
                            <button type="submit" class="<?= $primary ?>">Save colours</button>
                        </div>
                    <?php endif; ?>
                </form>

                <?php if ($settingsCanEdit && $d['custom']): ?>
                    <form method="post" action="<?= esc(site_url($base . '/branding/reset')) ?>" data-dialog-form class="border-t border-gray-100 pt-3">
                        <?= csrf_field() ?>
                        <button type="submit" class="<?= $danger ?>">Reset to the Mentora colours</button>
                    </form>
                <?php endif; ?>
            </section>

            <section class="<?= $section ?>" aria-labelledby="preview-heading">
                <h2 id="preview-heading" class="<?= $heading ?>">Preview</h2>
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

</div>

<?= $this->endSection() ?>
