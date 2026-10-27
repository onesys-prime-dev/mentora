<?php
/**
 * The dean's department in numbers: sub-departments, programs, the courses running now, the instructors
 * teaching them and the students enrolled. Data: DeanDashboardService::overview()['snapshot'] plus the
 * department's name.
 *
 * @var array{name: string, sub_departments: int, programs: int, courses: int, instructors: int, students: int} $data
 */
$rows = [
    ['Sub-departments', $data['sub_departments'], 'hat'],
    ['Programs', $data['programs'], 'modules'],
    ['Courses running', $data['courses'], 'tasks'],
    ['Instructors', $data['instructors'], 'user'],
    ['Students', $data['students'], 'user'],
];
?>

<!-- department snapshot (dean) -->
<div class="flex flex-col bg-white rounded-3xl shadow-sm overflow-clip" data-widget="snapshot">

    <div class="px-5 py-4 border-b border-[#F1F3F8]">
        <span class="block text-[11px] font-bold uppercase tracking-wide text-[#6B7488]">Your department</span>
        <span class="block text-[15px] font-bold text-[#0A0F1E]"><?= esc($data['name']) ?></span>
    </div>

    <dl class="flex flex-col">
        <?php foreach ($rows as [$label, $value, $icon]): ?>
            <div class="flex items-center justify-between gap-3 px-5 py-3 border-b border-[#F6F7FA] last:border-b-0">
                <dt class="flex items-center gap-2.5 text-[13px] text-[#4A5468]">
                    <?php // Given its colour directly: an icon registered in white (as `tasks` is) would vanish on the white card. ?>
                    <span class="flex"><?= icon($icon, 'w-4 h-4 text-[#0040A6]') ?></span>
                    <?= esc($label) ?>
                </dt>
                <dd class="text-sm font-bold text-[#1A1C1E]"><?= esc(number_format((int) $value)) ?></dd>
            </div>
        <?php endforeach; ?>
    </dl>

</div>
