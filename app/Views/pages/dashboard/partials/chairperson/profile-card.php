<?php
/**
 * Chairperson dashboard's own flat-card visual style (see the redesign
 * notes on branch fix/chair-dashboard-redesign) — deliberately different
 * from the glass-card look the rest of the app (including student and
 * instructor dashboards) still uses, so this partial lives under its own
 * chairperson/ subfolder rather than forking the shared profile-card.php.
 *
 * Same data shape as the shared profile-card: DashboardService::getProfileCardData(), plus an optional
 * `role_label` for the pill (the dean's dashboard reuses this card and says DEAN).
 */
$fullName = trim(($data['first_name'] ?? '') . ' ' . ($data['last_name'] ?? ''));

$secondaryLine = isset($data['program_name'])
    ? str_replace('Bachelor of Science in', 'BS in', $data['program_name'])
    : null;
?>

<!-- profile card (chairperson) -->
<div class="shrink-0 flex flex-col items-center gap-1 bg-white rounded-3xl p-6 text-center shadow-md ring-1 ring-black/5">

    <img
        class="w-18 h-18 rounded-full object-cover mb-2"
        src="<?= base_url('assets/images/placeholder/profile.png') ?>"
        alt="Profile photo of <?= esc($fullName) ?>">

    <span class="text-[17px] font-bold leading-tight text-[#0A0F1E]">
        <?= esc($fullName) ?>
    </span>

    <?php if ($secondaryLine !== null && $secondaryLine !== ''): ?>
        <span class="text-[13px] text-[#6B7488]">
            <?= esc($secondaryLine) ?>
        </span>
    <?php endif; ?>

    <span class="mt-2 text-[11px] font-bold tracking-wide text-[#0040A6] bg-[#E8EEFC] rounded-full px-3 py-1">
        <?= esc($data['role_label'] ?? 'CHAIRPERSON') ?>
    </span>

</div>
