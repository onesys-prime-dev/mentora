<?php
/*
|--------------------------------------------------------------------------
| Course info — the reference facts about this section
|--------------------------------------------------------------------------
| Instructor (or Section, for the instructor — who already knows who they
| are), Schedule, Location, Term. Schedule and room come from
| course_schedules via OverviewService; nothing here is typed in.
*/

$description = trim((string) ($overview['description'] ?? ''));

$blocks = $schedule ?? [];

$rooms = array_values(array_unique(array_filter(array_column($blocks, 'room'))));
$modes = array_values(array_unique(array_column($blocks, 'mode')));

$cards = [];

if ($role === 'student') {

    $instructorId = (int) ($overview['instructor_id'] ?? 0);

    $cards[] = [
        'label'  => 'Instructor',
        // Built for this viewer by OverviewService::withInstructorAvatar; plain when they may not see the instructor.
        'avatar' => $instructorId > 0
            ? ($overview['instructor_avatar'] ?? ['person' => ['id' => $instructorId, 'name' => (string) ($overview['instructor_name'] ?? '')], 'interactive' => false])
            : null,
        'icon'   => 'user',
        'lines'  => array_filter([
            $overview['instructor_name'] ?? null,
            $overview['instructor_email'] ?? null,
        ]) ?: ['Not assigned yet'],
    ];

} else {

    $enrolled = (int) ($overview['enrolled'] ?? 0);
    $capacity = (int) ($overview['capacity'] ?? 0);

    $cards[] = [
        'label' => 'Section',
        'icon'  => 'hat',
        'lines' => [
            ($overview['course_code'] ?? '') . ' - ' . ($overview['section_code'] ?? ''),
            $enrolled . ' of ' . $capacity . ' enrolled',
        ],
    ];

}

$cards[] = [
    'label' => 'Schedule',
    'icon'  => 'time',
    'lines' => $blocks !== []
        ? array_merge(...array_map(static fn (array $b) => [$b['days'], $b['time']], $blocks))
        : ['Not scheduled yet'],
];

$cards[] = [
    'label' => 'Location',
    'icon'  => 'door',
    'lines' => $rooms !== []
        ? [implode(', ', $rooms), implode(' / ', $modes)]
        : [$modes !== [] ? implode(' / ', $modes) : 'Not set'],
];

$cards[] = [
    'label' => 'Term',
    'icon'  => 'calendar',
    'lines' => array_filter([
        $overview['term_name'] ?? null,
        isset($overview['academic_year']) ? 'A.Y. ' . $overview['academic_year'] : null,
    ]) ?: ['—'],
];
?>

<div class="flex flex-col gap-4 p-4 pb-6 max-lg:@xs:p-6 max-lg:@xs:pb-8 lg:p-6 lg:pb-8 border border-[#A9A9A9]/50 rounded-2xl">

    <div class="flex items-center gap-3">

        <div class="w-5 h-5 flex justify-center items-center text-xs font-semibold font-lao text-brand border border-brand rounded-[100%]">
            i
        </div>

        <span class="text-lg text-brand">
            Course Description
        </span>

    </div>

    <p class="text-sm leading-relaxed <?= $description === '' ? 'text-[#767676]' : '' ?>">
        <?= $description !== '' ? esc($description) : 'No description has been added for this course yet.' ?>
    </p>

</div>

<?php // Two cards to a row once the column has room for them; from lg always two, as before. ?>
<div class="@container w-full min-w-0">
<div class="grid grid-cols-1 gap-6 @md:grid-cols-2 lg:grid-cols-2">

    <?php foreach ($cards as $card): ?>

        <div class="flex min-w-0 gap-4 p-6 border border-[#A9A9A9]/50 rounded-2xl">

            <?php if (! empty($card['avatar'])): ?>

                <div class="mt-1 shrink-0">
                    <?= view('components/avatar', $card['avatar'] + ['size' => 'lg'], ['saveData' => false]) ?>
                </div>

            <?php else: ?>

                <div class="w-13 h-13 mt-1 shrink-0 flex justify-center items-center bg-black/6 rounded-[100%]">
                    <?= icon($card['icon'], 'h-6 w-6') ?>
                </div>

            <?php endif; ?>

            <div class="flex min-w-0 flex-col">

                <span class="uppercase text-sm font-bold text-[#767676]">
                    <?= esc($card['label']) ?>
                </span>

                <?php foreach ($card['lines'] as $line): ?>
                    <span class="text-md text-[#1A1C1E] break-words">
                        <?= esc($line) ?>
                    </span>
                <?php endforeach; ?>

            </div>

        </div>

    <?php endforeach; ?>

</div>
</div>
