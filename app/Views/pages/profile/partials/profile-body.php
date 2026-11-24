<?php
/**
 * A profile's body, drawn from ProfileReader output: only the keys that are present are drawn, so a
 * hidden field is never in the HTML. Shared by /profile now, the profile modal and /people/{id} later.
 *
 * @var array<string, mixed> $p      ProfileReader output
 * @var array<string, mixed> $own    owner-only extras: official, student_number, portal_on (empty for a viewer)
 * @var array<string, mixed> $avatar AvatarData row
 */

$own       = $own ?? [];
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
    <div class="h-20 bg-linear-to-r from-brand-dark to-brand sm:h-24" aria-hidden="true"></div>
    <div class="-mt-10 flex flex-wrap items-end gap-x-5 gap-y-3 px-4 pb-5 sm:-mt-12 sm:px-6">
        <div class="rounded-full ring-4 ring-white">
            <?= view('components/avatar', ['person' => $avatar, 'size' => 'xl'], ['saveData' => false]) ?>
        </div>
        <div class="flex min-w-0 flex-1 basis-56 flex-col gap-1">
            <p class="font-display text-xl font-bold leading-tight text-[#1A1C1E] sm:text-2xl" data-profile-name>
                <?= esc($p['display_name']) ?>
                <?php if (! empty($p['pronouns'])): ?>
                    <span class="font-sans text-sm font-medium text-[#646464]"><?= esc($p['pronouns']) ?></span>
                <?php endif; ?>
            </p>
            <?php if ($legal !== '' && $legal !== $p['display_name']): ?>
                <p class="text-xs text-[#646464]">Legal name: <?= esc($legal) ?></p>
            <?php endif; ?>
            <?php if (! empty($p['roles'])): ?>
                <ul class="mt-1 flex flex-wrap gap-1.5" aria-label="Roles">
                    <?php foreach ($p['roles'] as $role): ?>
                        <li class="rounded-full bg-brand-tint px-2.5 py-0.5 text-xs font-semibold text-brand">
                            <?= esc($roleName((string) $role['role'])) ?><?= $role['detail'] !== null && $role['detail'] !== '' ? ' · ' . esc($role['detail']) : '' ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
    </div>
</section>

<?php if (array_key_exists('bio', $p) || $staffInfo !== null): ?>
    <div class="grid grid-cols-1 gap-4 <?= array_key_exists('bio', $p) && $staffInfo !== null ? 'lg:grid-cols-2' : '' ?>">

        <?php if (array_key_exists('bio', $p)): ?>
            <section class="<?= $card ?>" aria-labelledby="profile-about" data-profile-about>
                <h2 id="profile-about" class="<?= $heading ?>">About</h2>
                <?php if (trim((string) $p['bio']) !== ''): ?>
                    <p class="whitespace-pre-line break-words text-sm text-[#1A1C1E]"><?= esc($p['bio']) ?></p>
                <?php else: ?>
                    <p class="text-sm"><?= $empty ?></p>
                <?php endif; ?>
            </section>
        <?php endif; ?>

        <?php if ($staffInfo !== null): ?>
            <section class="<?= $card ?>" aria-labelledby="profile-office" data-profile-office>
                <h2 id="profile-office" class="<?= $heading ?>">Office &amp; consultation</h2>
                <dl class="<?= $dl ?>">
                    <dt class="<?= $dt ?>">Office</dt>
                    <dd class="<?= $dd ?>"><?= trim((string) $staffInfo['office_location']) !== '' ? esc($staffInfo['office_location']) : $empty ?></dd>
                    <dt class="<?= $dt ?>">Consultation</dt>
                    <dd class="<?= $dd ?>"><?= trim((string) $staffInfo['consultation_hours']) !== '' ? esc($staffInfo['consultation_hours']) : $empty ?></dd>
                    <dt class="<?= $dt ?>">Work phone</dt>
                    <dd class="<?= $dd ?>"><?= trim((string) $staffInfo['work_phone']) !== '' ? esc($staffInfo['work_phone']) : $empty ?></dd>
                </dl>
            </section>
        <?php endif; ?>

    </div>
<?php endif; ?>

<?php if (array_key_exists('email', $p) || $official !== []): ?>
    <section class="<?= $card ?>" aria-labelledby="profile-official" data-profile-official>
        <h2 id="profile-official" class="<?= $heading ?>">
            <span>Official details</span>
            <span class="inline-flex items-center gap-1.5 text-xs font-normal text-[#646464]"><?= icon('lock', 'h-3.5 w-3.5 text-[#646464]') ?>Managed by your school</span>
        </h2>
        <dl class="<?= $dl ?>">
            <?php foreach ($official as $label => $value): ?>
                <dt class="<?= $dt ?>"><?= esc($label) ?></dt>
                <dd class="<?= $dd ?>"><?= esc($value) ?></dd>
            <?php endforeach; ?>
            <?php if (array_key_exists('email', $p)): ?>
                <dt class="<?= $dt ?>">Email</dt>
                <dd class="<?= $dd ?>"><?= esc($p['email']) ?></dd>
            <?php endif; ?>
            <?php if (array_key_exists('student_number', $own)): ?>
                <dt class="<?= $dt ?>">Student number</dt>
                <dd class="<?= $dd ?>"><?= $own['student_number'] !== '' ? esc($own['student_number']) : '<span class="text-[#646464]">Not set</span>' ?></dd>
            <?php endif; ?>
            <?php foreach (['program' => 'Program', 'year' => 'Year', 'section' => 'Section'] as $key => $label): ?>
                <?php if (($p['student_details'][$key] ?? '') !== ''): ?>
                    <dt class="<?= $dt ?>"><?= $label ?></dt>
                    <dd class="<?= $dd ?>"><?= esc($p['student_details'][$key]) ?></dd>
                <?php endif; ?>
            <?php endforeach; ?>
        </dl>
    </section>
<?php endif; ?>

<?php if (array_key_exists('parents', $p)): ?>
    <section class="<?= $card ?>" aria-labelledby="profile-parents" data-profile-parents>
        <h2 id="profile-parents" class="<?= $heading ?>">Parents who can see your progress</h2>
        <?php if ($p['parents'] === []): ?>
            <p class="text-sm text-[#646464]">No parent can see your progress.</p>
        <?php else: ?>
            <ul class="flex flex-col divide-y divide-gray-100">
                <?php foreach ($p['parents'] as $name): ?>
                    <li class="flex items-center gap-3 py-2 text-sm font-semibold text-[#1A1C1E]"><?= icon('user', 'h-4 w-4 shrink-0 text-brand') ?><?= esc($name) ?></li>
                <?php endforeach; ?>
            </ul>
            <p class="text-xs text-[#646464]">They can see your courses, deadlines and grades. They cannot change anything. Your school decides who is linked to you.</p>
        <?php endif; ?>
    </section>
<?php endif; ?>

<?php if (array_key_exists('children', $p)): ?>
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

<?php if (array_key_exists('courses', $p) && $isStaff && (in_array('instructor', $roles, true) || $p['courses'] !== [])): ?>
    <section class="<?= $card ?>" aria-labelledby="profile-term" data-profile-term>
        <h2 id="profile-term" class="<?= $heading ?>">This term</h2>
        <?php if ($p['courses'] === []): ?>
            <p class="text-sm text-[#646464]">No courses this term.</p>
        <?php else: ?>
            <ul class="flex flex-col divide-y divide-gray-100">
                <?php foreach ($p['courses'] as $course): ?>
                    <li class="py-2 text-sm text-[#1A1C1E]"><span class="font-semibold"><?= esc($course['code']) ?></span> <?= esc($course['title']) ?></li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </section>
<?php endif; ?>
