<?php
/**
 * Same data as the shared program-snapshot partial
 * (DashboardService::getProgramSnapshotData()), restyled as compact stat
 * rows instead of three separate tiles — see profile-card.php's note on
 * why this lives under chairperson/ rather than forking the shared one.
 */
$metrics = [
    ['label' => 'Courses',     'value' => (int) ($data['courses'] ?? 0),     'icon' => 'hat',     'tint' => 'bg-[#E8EEFC]', 'color' => 'text-[#0040A6]'],
    ['label' => 'Instructors', 'value' => (int) ($data['instructors'] ?? 0), 'icon' => 'user',    'tint' => 'bg-[#E6F4EC]', 'color' => 'text-[#1E8A4C]'],
    ['label' => 'Students',    'value' => (int) ($data['students'] ?? 0),    'icon' => 'lessons', 'tint' => 'bg-[#FFF1D6]', 'color' => 'text-[#946200]'],
];
?>

<!-- program snapshot (chairperson) -->
<div class="flex flex-col gap-3 bg-white rounded-3xl p-5 shadow-md ring-1 ring-black/5">

    <span class="text-xs font-bold uppercase tracking-wide text-[#4A5468]">Program snapshot</span>

    <div class="flex flex-col">

        <?php foreach ($metrics as $index => $metric): ?>

            <div class="flex items-center justify-between gap-3 py-2.5 <?= $index > 0 ? 'border-t border-[#F1F3F8]' : '' ?>">

                <div class="flex items-center gap-2.5 min-w-0">
                    <span class="w-9 h-9 shrink-0 rounded-[10px] <?= $metric['tint'] ?> <?= $metric['color'] ?> flex items-center justify-center">
                        <?php // Given the row's colour directly: `lessons` is registered in white and would vanish on the tint. ?>
                        <?= icon($metric['icon'], 'w-5 h-5 ' . $metric['color']) ?>
                    </span>
                    <span class="text-sm text-[#2A3142] truncate"><?= esc($metric['label']) ?></span>
                </div>

                <span class="text-xl font-bold text-[#0A0F1E] shrink-0">
                    <?= esc((string) $metric['value']) ?>
                </span>

            </div>

        <?php endforeach; ?>

    </div>

</div>
