<?php
/**
 * Cohort completion per module — a teaching signal rather than an admin
 * report. A row that drops sharply against the ones above it says the
 * module is the problem, not the students.
 *
 * Aggregates learning_completions by module across each offering's roster.
 * Placeholder rows until the query lands.
 */
$progress = $data['progress'] ?? [
    [
        'course_code' => 'IT101', 'section_code' => 'BSIT-1A',
        'modules' => [
            ['title' => 'M1 - Foundations',     'completion' => 92],
            ['title' => 'M2 - Hardware Basics', 'completion' => 78],
            ['title' => 'M3 - Number Systems',  'completion' => 31],
        ],
    ],
    [
        'course_code' => 'IT102', 'section_code' => 'BSIT-1B',
        'modules' => [
            ['title' => 'M1 - Syntax & Flow',   'completion' => 88],
            ['title' => 'M2 - Functions',       'completion' => 54],
        ],
    ],
];

/** A module far below the one before it is where the cohort stalled. */
$barColor = function (int $completion): string {
    if ($completion < 40) {
        return 'bg-red-600';
    }

    if ($completion < 70) {
        return 'bg-amber-600';
    }

    return 'bg-emerald-600';
};
?>

<!-- class progress -->
<div class="h-full min-h-0 flex flex-col bg-white/20 backdrop-blur-xl border border-white/30 shadow-xl rounded-3xl overflow-clip">

    <div class="shrink-0 flex items-center justify-between px-6 py-3 bg-black/10">
        <span class="text-xl font-display">Class Progress</span>
        <span class="text-xs text-gray-700">by module</span>
    </div>

    <div class="flex-1 min-h-0 overflow-y-auto scroll-area px-6 py-4">

        <?php if ($progress === []): ?>

            <div class="h-full flex flex-col justify-center items-center gap-1 text-center py-6">
                <span class="text-sm font-medium text-gray-700">Nothing to measure yet</span>
                <span class="text-xs text-gray-600">Module completion shows here once students start working.</span>
            </div>

        <?php else: ?>

            <!--
                Divider rather than spacing between sections: the module rows
                inside a section are already separated by gaps, so more gap
                alone never reads as a group boundary. divide-black/10 sits a
                step darker than the /5 used for row lists, so a section break
                outranks a row break.
            -->
            <div class="flex flex-col divide-y divide-black/10">

                <?php foreach ($progress as $course): ?>

                    <div class="flex flex-col gap-2 py-4 first:pt-0 last:pb-0">

                        <div class="flex items-center gap-1 text-xs">
                            <span class="font-bold text-gray-800"><?= esc($course['course_code']) ?></span>
                            <span class="text-gray-600">-</span>
                            <span class="text-gray-700"><?= esc($course['section_code']) ?></span>
                        </div>

                        <div class="flex flex-col gap-2">

                            <?php foreach ($course['modules'] as $module): ?>

                                <div class="flex flex-col gap-1">
                                    <div class="flex justify-between items-center gap-2 text-[11px]">
                                        <span class="truncate text-gray-700"><?= esc($module['title']) ?></span>
                                        <span class="shrink-0 font-medium"><?= esc($module['completion']) ?>%</span>
                                    </div>

                                    <div class="h-2 bg-black/10 rounded-full overflow-clip">
                                        <div
                                            class="h-full <?= $barColor((int) $module['completion']) ?> rounded-full"
                                            style="width: <?= esc($module['completion']) ?>%;"></div>
                                    </div>
                                </div>

                            <?php endforeach; ?>

                        </div>

                    </div>

                <?php endforeach; ?>

            </div>

        <?php endif; ?>

    </div>

</div>
