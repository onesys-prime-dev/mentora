<?php
/**
 * Direct entry into each enrolled course — the most common reason a student
 * opens the dashboard at all. Row shape matches pages/courses/index.php
 * (offering_id, slug, course_code, section_code, course_title) so wiring this
 * to EnrollmentModel + LearningCompletionModel is a drop-in later.
 */
$userRole = esc($user->getGroups()[0]);

$courses = $data['courses'] ?? [
    ['offering_id' => 1, 'slug' => 'introduction-to-computing', 'course_code' => 'IT101', 'section_code' => 'BSIT-1A', 'course_title' => 'Introduction to Computing',   'progress' => 62],
    ['offering_id' => 2, 'slug' => 'computer-programming-1',    'course_code' => 'IT102', 'section_code' => 'BSIT-1A', 'course_title' => 'Computer Programming 1',     'progress' => 25],
];
?>

<!-- my courses -->
<div class="h-full min-h-0 flex flex-col bg-white/20 backdrop-blur-xl border border-white/30 shadow-xl rounded-3xl overflow-clip">

    <div class="shrink-0 flex items-center justify-between px-6 py-3 bg-black/10">
        <span class="text-xl font-display">My Courses</span>

        <a
            class="text-xs text-blue-800 font-medium hover:underline"
            href="<?= site_url($userRole . '/courses') ?>">
            View all
        </a>
    </div>

    <div class="flex-1 min-h-0 overflow-y-auto scroll-area px-6 py-4">

        <?php if ($courses === []): ?>

            <div class="h-full flex flex-col justify-center items-center gap-1 text-center py-6">
                <span class="text-sm font-medium text-gray-700">No courses yet</span>
                <span class="text-xs text-gray-600">Courses appear here once you're enrolled this term.</span>
            </div>

        <?php else: ?>

            <div class="flex flex-col gap-4">

                <?php foreach ($courses as $course): ?>

                    <a
                        class="flex flex-col gap-3 p-4 bg-white/30 border border-black/5 shadow-md rounded-2xl transition hover:bg-white/50"
                        href="<?= site_url($userRole . '/courses/' . $course['offering_id'] . '/' . $course['slug']) ?>">

                        <div class="flex items-center gap-3">

                            <div class="w-10 h-10 shrink-0 flex justify-center items-center bg-blue-800 rounded-full">
                                <?= icon('code', 'w-5 h-5 text-white') ?>
                            </div>

                            <div class="flex flex-col min-w-0">
                                <div class="flex gap-1 text-xs text-gray-600">
                                    <span class="font-bold"><?= esc($course['course_code']) ?></span>
                                    <span>-</span>
                                    <span class="truncate"><?= esc($course['section_code']) ?></span>
                                </div>

                                <span class="text-sm font-bold leading-tight truncate">
                                    <?= esc($course['course_title']) ?>
                                </span>
                            </div>

                        </div>

                        <div class="flex flex-col gap-1">
                            <div class="flex justify-between text-[11px] text-gray-600">
                                <span>PROGRESS</span>
                                <span><?= esc($course['progress']) ?>%</span>
                            </div>

                            <div class="h-2 bg-black/10 rounded-full overflow-clip">
                                <div
                                    class="h-full bg-blue-800 rounded-full"
                                    style="width: <?= esc($course['progress']) ?>%;"></div>
                            </div>
                        </div>

                    </a>

                <?php endforeach; ?>

            </div>

        <?php endif; ?>

    </div>

</div>
