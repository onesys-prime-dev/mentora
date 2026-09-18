<?php
/**
 * Identity strip. Same shape for every role, different second and third
 * lines: a student is identified by their number and programme, an
 * instructor by the department they sit in and the rank they hold.
 *
 * NOTE: user_profiles carries student_number and program_id but has no
 * department or academic_rank column yet, so the instructor lines fall back
 * to placeholders. Wiring them needs either two nullable columns on
 * user_profiles — mirroring how student_number is already role-specific —
 * or a departments table if departments become first-class.
 */
$userRole = esc($user->getGroups()[0]);

$fullName = trim(($data['first_name'] ?? '') . ' ' . ($data['last_name'] ?? ''));

if ($userRole === 'instructor') {
    $primaryLine = $data['academic_rank'] ?? 'Associate Professor';
    $secondaryLine = $data['department'] ?? 'Department of Information Technology';
} else {
    $primaryLine = $data['student_number'] ?? '';

    $secondaryLine = isset($data['program_name'])
        ? str_replace('Bachelor of Science in', 'BS in', $data['program_name'])
        : null;
}
?>

<!-- profile card -->
<div class="shrink-0 flex items-center gap-4 bg-white/20 backdrop-blur-xl border border-white/30 shadow-xl rounded-3xl p-4">

    <img
        class="w-14 h-14 shrink-0 rounded-full object-cover"
        src="<?= base_url('assets/images/placeholder/profile.png') ?>"
        alt="Profile photo of <?= esc($fullName) ?>">

    <div class="flex flex-col gap-0.5 min-w-0">
        <span class="text-lg font-bold leading-tight truncate">
            <?= esc($fullName) ?>
        </span>

        <?php if ($primaryLine !== null && $primaryLine !== ''): ?>
            <span class="text-xs text-gray-700">
                <?= esc($primaryLine) ?>
            </span>
        <?php endif; ?>

        <?php if ($secondaryLine !== null && $secondaryLine !== ''): ?>
            <span class="text-xs text-gray-700 leading-snug">
                <?= esc($secondaryLine) ?>
            </span>
        <?php endif; ?>
    </div>

</div>
