<?php
/**
 * The instructor mirror of My Courses: the sections I teach this term, how
 * full each one is, and when it meets.
 *
 * Joins instructor_assignments -> course_offerings -> courses, with an
 * enrolled count against offering capacity and meeting blocks from the
 * course_schedules table. Placeholder rows until the query lands.
 */
$userRole = esc($user->getGroups()[0]);

$sections = $data['sections'] ?? [
    [
        'offering_id' => 1, 'slug' => 'introduction-to-computing',
        'course_code' => 'IT101', 'section_code' => 'BSIT-1A',
        'course_title' => 'Introduction to Computing',
        'enrolled' => 38, 'capacity' => 40,
        'schedule' => 'MWF 8:00-9:30', 'room' => 'Room 33A',
    ],
    [
        'offering_id' => 2, 'slug' => 'computer-programming-1',
        'course_code' => 'IT102', 'section_code' => 'BSIT-1B',
        'course_title' => 'Computer Programming 1',
        'enrolled' => 40, 'capacity' => 40,
        'schedule' => 'TTh 13:00-15:00', 'room' => 'Lab 2',
    ],
];
?>

<!-- teaching load -->
<div class="h-full min-h-0 flex flex-col bg-white/20 backdrop-blur-xl border border-white/30 shadow-xl rounded-3xl overflow-clip">

    <div class="shrink-0 flex items-center justify-between px-6 py-3 bg-black/10">
        <span class="text-xl font-display">Teaching Load</span>

        <a
            class="text-xs text-blue-800 font-medium hover:underline"
            href="<?= site_url($userRole . '/courses') ?>">
            View all
        </a>
    </div>

    <div class="flex-1 min-h-0 overflow-y-auto scroll-area px-6 py-4">

        <?php if ($sections === []): ?>

            <div class="h-full flex flex-col justify-center items-center gap-1 text-center py-6">
                <span class="text-sm font-medium text-gray-700">No sections assigned</span>
                <span class="text-xs text-gray-600">Sections you teach this term will appear here.</span>
            </div>

        <?php else: ?>

            <div class="flex flex-col gap-4">

                <?php foreach ($sections as $section): ?>
                    <?php
                    $capacity = max(1, (int) $section['capacity']);
                    $fill = min(100, (int) round($section['enrolled'] / $capacity * 100));
                    $isFull = $section['enrolled'] >= $section['capacity'];
                    ?>

                    <a
                        class="group flex flex-col gap-3 p-4 bg-white/30 border border-black/5 shadow-md rounded-2xl transition hover:bg-white/50"
                        href="<?= site_url($userRole . '/courses/' . $section['offering_id'] . '/' . $section['slug']) ?>">

                        <div class="flex items-center gap-3">

                            <div class="w-10 h-10 shrink-0 flex justify-center items-center bg-blue-800 rounded-full transition-transform duration-150 group-hover:scale-110">
                                <?= icon('code', 'w-5 h-5 text-white') ?>
                            </div>

                            <div class="flex flex-col min-w-0">
                                <div class="flex gap-1 text-xs text-gray-600">
                                    <span class="font-bold"><?= esc($section['course_code']) ?></span>
                                    <span>-</span>
                                    <span class="truncate"><?= esc($section['section_code']) ?></span>
                                </div>

                                <span class="text-sm font-bold leading-tight truncate transition-colors duration-150 group-hover:text-blue-900">
                                    <?= esc($section['course_title']) ?>
                                </span>
                            </div>

                        </div>

                        <div class="flex items-center gap-2 text-[11px] text-gray-700">
                            <?= icon('time', 'w-3.5 h-3.5 text-gray-600') ?>
                            <span class="truncate"><?= esc($section['schedule']) ?></span>
                            <span>·</span>
                            <span class="truncate"><?= esc($section['room']) ?></span>
                        </div>

                        <div class="flex flex-col gap-1">
                            <div class="flex justify-between text-[11px] text-gray-600">
                                <span>ENROLLED</span>
                                <span class="<?= $isFull ? 'text-amber-700 font-medium' : '' ?>">
                                    <?= esc($section['enrolled']) ?> / <?= esc($section['capacity']) ?>
                                    <?= $isFull ? '· Full' : '' ?>
                                </span>
                            </div>

                            <div class="h-2 bg-black/10 rounded-full overflow-clip">
                                <div
                                    class="h-full <?= $isFull ? 'bg-amber-600' : 'bg-blue-800' ?> rounded-full"
                                    style="width: <?= $fill ?>%;"></div>
                            </div>
                        </div>

                    </a>

                <?php endforeach; ?>

            </div>

        <?php endif; ?>

    </div>

</div>
