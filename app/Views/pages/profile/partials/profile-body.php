<?php
/**
 * A profile's body, drawn from ProfileReader output: only the keys that are present are drawn, so a
 * hidden field is never in the HTML. Shared by /profile, the profile modal and /people/{id}.
 *
 * @var array<string, mixed> $p      ProfileReader output
 * @var bool                 $isOwner the owner's own /profile (true) or anyone viewing it, the preview included (false)
 * @var string               $actions HTML for the band's action row (Message), viewers only
 * @var array<string, mixed> $own    owner-only extras: official, student_number, portal_on (never built for a viewer)
 * @var array<string, mixed> $avatar AvatarData row
 * @var array{has: bool, on: bool}|null $photo the owner's photo state (names the pencil: Add or Change photo)
 * @var array<string, mixed>|null $edit set only on the owner's own page: their editable values (pencils and dialogs); null for a viewer
 */

$isOwner   = $isOwner ?? false;
$own       = $isOwner ? ($own ?? []) : [];
$edit      = $isOwner ? ($edit ?? null) : null;
$actions   = $actions ?? '';
$gone      = array_key_exists('deactivated_badge', $p);
$pencil    = static fn (string $dialog, string $label): string => $edit === null ? '' : '<button type="button" data-dialog-open="' . $dialog . '" aria-label="' . esc($label, 'attr') . '" class="inline-flex items-center justify-center rounded-full p-1.5 text-brand transition hover:bg-brand-tint focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/50 pointer-coarse:min-h-11 pointer-coarse:min-w-11">' . icon('pencil', 'h-4 w-4') . '</button>';
$card      = 'flex flex-col gap-3 rounded-2xl bg-white p-4 shadow-sm sm:p-6';
$heading   = 'flex flex-wrap items-center justify-between gap-x-3 gap-y-1 text-sm font-bold text-brand';
$dl        = 'grid grid-cols-1 gap-x-6 gap-y-0 text-sm sm:grid-cols-[9rem_minmax(0,1fr)]';
$dt        = 'font-medium text-[#646464] sm:py-1';
$dd        = 'min-w-0 break-words pb-2 text-[#1A1C1E] sm:py-1 sm:pb-1';
$empty     = '<span class="text-[#646464]">Not added yet</span>';
$roleName  = static fn (string $r): string => $r === 'admin' ? 'System admin' : ucfirst(str_replace('_', ' ', $r));
$staffInfo = $p['staff_info'] ?? null;
$roles     = array_column($p['roles'] ?? [], 'role');
$isStaff   = array_intersect($roles, ['dean', 'chairperson', 'instructor', 'institutional_admin']) !== [];
$legal     = (string) ($p['legal_name'] ?? '');
$official  = $own['official'] ?? [];
?>

<?php // Header band: the one bold element. ?>
<section class="overflow-hidden rounded-2xl bg-white shadow-sm" aria-label="<?= esc($p['display_name'], 'attr') ?>" data-profile-band>
    <div class="h-20 sm:h-24 <?= $gone ? 'bg-gray-300' : 'bg-linear-to-r from-brand-dark to-brand' ?>" aria-hidden="true"></div>
    <div class="-mt-10 flex flex-wrap items-end gap-x-5 gap-y-3 px-4 pb-5 sm:-mt-12 sm:px-6">
        <div class="relative self-start rounded-full ring-4 ring-white">
            <?= view('components/avatar', ['person' => $avatar, 'size' => 'xl'], ['saveData' => false]) ?>
            <?php if ($edit !== null): ?>
                <button type="button" data-dialog-open="profile-photo" data-photo-pencil aria-label="<?= ($photo['has'] ?? false) ? 'Change photo' : 'Add photo' ?>" class="absolute -bottom-1 -right-1 inline-flex items-center justify-center rounded-full bg-white p-1.5 text-brand shadow-sm ring-1 ring-brand/30 transition hover:bg-brand-tint focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/50 pointer-coarse:min-h-11 pointer-coarse:min-w-11"><?= icon('pencil', 'h-4 w-4') ?></button>
            <?php endif; ?>
        </div>
        <div class="flex min-w-0 flex-1 basis-56 flex-col gap-1 sm:pt-14">
            <p class="font-display text-xl font-bold leading-tight text-[#1A1C1E] sm:text-2xl" data-profile-name>
                <?= esc($p['display_name']) ?>
                <?php if (! empty($p['pronouns'])): ?>
                    <span class="font-sans text-sm font-medium text-[#646464]"><?= esc($p['pronouns']) ?></span>
                <?php endif; ?>
            </p>
            <?php if ($gone): ?>
                <p><span class="inline-flex rounded-full bg-gray-100 px-2.5 py-0.5 text-xs font-semibold text-gray-700" data-profile-deactivated>Deactivated</span></p>
            <?php endif; ?>
            <?php if ($legal !== '' && $legal !== $p['display_name']): ?>
                <p class="text-xs text-[#646464]">Legal name: <?= esc($legal) ?></p>
            <?php endif; ?>
            <?php if (! empty($p['roles'])): ?>
                <ul class="mt-1 flex flex-wrap gap-1.5" aria-label="Roles">
                    <?php foreach ($p['roles'] as $role): ?>
                        <li class="rounded-full px-2.5 py-0.5 text-xs font-semibold <?= $gone ? 'bg-gray-100 text-gray-700' : 'bg-brand-tint text-brand' ?>">
                            <?= esc($roleName((string) $role['role'])) ?><?= $role['detail'] !== null && $role['detail'] !== '' ? ' · ' . esc($role['detail']) : '' ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
            <?php if ($gone): ?>
                <p class="mt-1 text-sm text-[#646464]">This account is no longer active.</p>
            <?php endif; ?>
        </div>
        <?php if ($actions !== ''): ?>
            <div class="flex flex-wrap gap-2 sm:pt-14"><?= $actions ?></div>
        <?php endif; ?>
        <?php if ($edit !== null): ?>
            <button type="button" data-dialog-open="profile-edit-identity" class="inline-flex items-center gap-2 rounded-full border border-brand/30 bg-white px-4 py-2 text-sm font-semibold text-brand transition hover:bg-brand-tint focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/50 pointer-coarse:min-h-11"><?= icon('pencil', 'h-4 w-4') ?>Edit name &amp; pronouns</button>
        <?php endif; ?>
    </div>
</section>

<?php
// A viewer never sees "Not added yet": empty cards and rows are left out; the owner keeps them (with the pencil).
$showBio    = array_key_exists('bio', $p) && ($edit !== null || trim((string) $p['bio']) !== '');
$officeRows = ['Office' => 'office_location', 'Consultation' => 'consultation_hours', 'Work phone' => 'work_phone'];
if ($staffInfo !== null && $edit === null) {
    $officeRows = array_filter($officeRows, static fn (string $k): bool => trim((string) $staffInfo[$k]) !== '');
}
$showOffice = $staffInfo !== null && $officeRows !== [];
?>
<?php if ($showBio || $showOffice): ?>
    <div class="grid grid-cols-1 gap-4 <?= $showBio && $showOffice ? 'lg:grid-cols-2' : '' ?>">

        <?php if ($showBio): ?>
            <section class="<?= $card ?>" aria-labelledby="profile-about" data-profile-about>
                <h2 id="profile-about" class="<?= $heading ?>">About<?= $pencil('profile-edit-about', 'Edit about') ?></h2>
                <?php if (trim((string) $p['bio']) !== ''): ?>
                    <p class="whitespace-pre-line break-words text-sm text-[#1A1C1E]"><?= esc($p['bio']) ?></p>
                <?php else: ?>
                    <p class="text-sm"><?= $empty ?></p>
                <?php endif; ?>
            </section>
        <?php endif; ?>

        <?php if ($showOffice): ?>
            <section class="<?= $card ?>" aria-labelledby="profile-office" data-profile-office>
                <h2 id="profile-office" class="<?= $heading ?>">Office &amp; consultation<?= ($edit['staff'] ?? false) ? $pencil('profile-edit-staff', 'Edit office and consultation') : '' ?></h2>
                <dl class="<?= $dl ?>">
                    <?php foreach ($officeRows as $label => $key): ?>
                        <dt class="<?= $dt ?>"><?= $label ?></dt>
                        <dd class="<?= $dd ?>"><?= trim((string) $staffInfo[$key]) !== '' ? esc($staffInfo[$key]) : $empty ?></dd>
                    <?php endforeach; ?>
                </dl>
            </section>
        <?php endif; ?>

    </div>
<?php endif; ?>

<?php if (array_key_exists('email', $p) && ! $isOwner && trim((string) $p['email']) !== ''): ?>
    <section class="<?= $card ?>" aria-labelledby="profile-contact" data-profile-contact>
        <h2 id="profile-contact" class="<?= $heading ?>">Contact</h2>
        <dl class="<?= $dl ?>">
            <dt class="<?= $dt ?>">Email</dt>
            <dd class="<?= $dd ?> min-w-0 break-all"><?= esc($p['email']) ?></dd>
        </dl>
    </section>
<?php endif; ?>

<?php
$studies = array_filter(['Program' => $p['student_details']['program'] ?? '', 'Year' => $p['student_details']['year'] ?? '', 'Section' => $p['student_details']['section'] ?? '', 'College' => $p['student_details']['college'] ?? ''], static fn ($v): bool => trim((string) $v) !== '');
?>
<?php if (array_key_exists('student_details', $p) && $studies !== []): ?>
    <section class="<?= $card ?>" aria-labelledby="profile-studies" data-profile-studies>
        <h2 id="profile-studies" class="<?= $heading ?>">Studies</h2>
        <dl class="<?= $dl ?>">
            <?php foreach ($studies as $label => $value): ?>
                <dt class="<?= $dt ?>"><?= $label ?></dt>
                <dd class="<?= $dd ?>"><?= esc($value) ?></dd>
            <?php endforeach; ?>
        </dl>
    </section>
<?php endif; ?>

<?php // Owner-worded cards: never for a viewer, even if the policy ever let the key through. ?>
<?php if ($isOwner && array_key_exists('parents', $p)): ?>
    <section class="<?= $card ?>" aria-labelledby="profile-parents" data-profile-parents>
        <h2 id="profile-parents" class="<?= $heading ?>">Parents who can see your progress</h2>
        <?php if ($p['parents'] === []): ?>
            <p class="text-sm text-[#646464]">No parent can see your progress.</p>
        <?php else: ?>
            <ul class="flex flex-col divide-y divide-gray-100">
                <?php foreach ($p['parents'] as $parent): ?>
                    <li class="flex items-center gap-3 py-2 text-sm font-semibold text-[#1A1C1E]"><?= icon('user', 'h-4 w-4 shrink-0 text-brand') ?><?= esc($parent['name']) ?></li>
                <?php endforeach; ?>
            </ul>
            <p class="text-xs text-[#646464]">They can see your courses, deadlines and grades. They cannot change anything. Your school decides who is linked to you.</p>
        <?php endif; ?>
    </section>
<?php endif; ?>

<?php if ($isOwner && array_key_exists('children', $p)): ?>
    <section class="<?= $card ?>" aria-labelledby="profile-children" data-profile-children>
        <h2 id="profile-children" class="<?= $heading ?>">Your children</h2>
        <?php if (($own['portal_on'] ?? true) === false): ?>
            <p class="text-sm text-[#646464]">The parent portal is switched off right now. Contact your school.</p>
        <?php elseif ($p['children'] === []): ?>
            <p class="text-sm text-[#646464]">You are not linked to a student yet.</p>
        <?php else: ?>
            <ul class="flex flex-col divide-y divide-gray-100">
                <?php foreach ($p['children'] as $child): ?>
                    <li class="flex items-center gap-3 py-2 text-sm font-semibold text-[#1A1C1E]"><?= icon('user', 'h-4 w-4 shrink-0 text-brand') ?><?= esc($child['name']) ?></li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </section>
<?php endif; ?>

<?php // The owner's page keeps the staff card (with its empty state); a viewer sees any subject's courses, or nothing. ?>
<?php if (array_key_exists('courses', $p) && ($isOwner ? $isStaff && (in_array('instructor', $roles, true) || $p['courses'] !== []) : $p['courses'] !== [])): ?>
    <section class="<?= $card ?>" aria-labelledby="profile-term" data-profile-term>
        <h2 id="profile-term" class="<?= $heading ?>">This term</h2>
        <?php if ($p['courses'] === []): ?>
            <p class="text-sm text-[#646464]">No courses this term.</p>
        <?php else: ?>
            <?php $groups = ['teaching' => 'Teaching', 'enrolled' => 'Enrolled']; ?>
            <?php foreach ($groups as $origin => $groupLabel): ?>
                <?php $inGroup = array_filter($p['courses'], static fn (array $c): bool => $c['origin'] === $origin); ?>
                <?php if ($inGroup === []) { continue; } ?>
                <?php if (! $isOwner || count(array_unique(array_column($p['courses'], 'origin'))) > 1): ?>
                    <h3 class="text-xs font-semibold uppercase tracking-wide text-[#646464]"><?= $groupLabel ?></h3>
                <?php endif; ?>
                <ul class="flex flex-col divide-y divide-gray-100">
                    <?php foreach ($inGroup as $course): ?>
                        <li class="py-2 text-sm text-[#1A1C1E]"><span class="font-semibold"><?= esc(trim($course['code'] . ' ' . $course['section'])) ?></span> &middot; <?= esc($course['title']) ?></li>
                    <?php endforeach; ?>
                </ul>
            <?php endforeach; ?>
        <?php endif; ?>
    </section>
<?php endif; ?>

<?php if ($isOwner && (array_key_exists('email', $p) || $official !== [] || array_key_exists('student_number', $own))): ?>
    <section class="<?= $card ?>" aria-labelledby="profile-official" data-profile-official>
        <h2 id="profile-official" class="<?= $heading ?>">
            <span>Official details</span>
            <?php if ($own['managed'] ?? true): ?>
                <span class="inline-flex items-center gap-1.5 text-xs font-normal text-[#646464]"><?= icon('lock', 'h-3.5 w-3.5 text-[#646464]') ?>Managed by your school</span>
            <?php elseif (($own['official_edit'] ?? null) !== null): ?>
                <?= '<button type="button" data-dialog-open="profile-official" aria-label="Edit official details" class="inline-flex items-center justify-center rounded-full p-1.5 text-brand transition hover:bg-brand-tint focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/50 pointer-coarse:min-h-11 pointer-coarse:min-w-11">' . icon('pencil', 'h-4 w-4') . '</button>' ?>
            <?php endif; ?>
        </h2>
        <?php
        // "Waiting for ..." beside a detail with an open correction request.
        $open    = $own['correction']['open'] ?? [];
        $waiting = static fn (string $field): string => isset($open[$field])
            ? ' <span class="ml-1 inline-flex items-center rounded-full bg-[#FFF1D6] px-2 py-0.5 text-[11px] font-semibold text-[#946200]" data-correction-waiting>' . ($open[$field] === 'platform' ? 'Waiting for an administrator' : 'Waiting for your school') . '</span>'
            : '';
        $keys = ['First name' => 'first_name', 'Middle name' => 'middle_name', 'Last name' => 'last_name', 'Suffix' => 'suffix'];
        ?>
        <dl class="<?= $dl ?>">
            <?php foreach ($official as $label => $value): ?>
                <dt class="<?= $dt ?>"><?= esc($label) ?></dt>
                <dd class="<?= $dd ?>"><?= esc($value) ?><?= $waiting($keys[$label] ?? '') ?></dd>
            <?php endforeach; ?>
            <?php if (array_key_exists('email', $p)): ?>
                <dt class="<?= $dt ?>">Email</dt>
                <dd class="<?= $dd ?>"><?= esc($p['email']) ?><?= $waiting('email') ?></dd>
            <?php endif; ?>
            <?php if (array_key_exists('student_number', $own)): ?>
                <dt class="<?= $dt ?>">Student number</dt>
                <dd class="<?= $dd ?>"><?= $own['student_number'] !== '' ? esc($own['student_number']) : '<span class="text-[#646464]">Not set</span>' ?><?= $waiting('student_number') ?></dd>
            <?php endif; ?>
        </dl>
        <?php if (($own['correction'] ?? null) !== null): ?>
            <p><button type="button" data-dialog-open="profile-correction" class="inline-flex items-center gap-2 rounded-full border border-brand/30 bg-white px-4 py-2 text-sm font-semibold text-brand transition hover:bg-brand-tint focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/50 pointer-coarse:min-h-11">Request a correction</button></p>
        <?php endif; ?>
    </section>
<?php endif; ?>
