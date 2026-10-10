<?php
/*
|--------------------------------------------------------------------------
| Course card — the one way a course is shown on the Courses page.
| Params: course, userRole, isInstructor.
|
| The cover is purely decorative and no text sits on it, so readability
| never depends on the artwork. It uses the same banner image as the Course
| Workspace (a per-course $course['banner_image'] can override it later).
|--------------------------------------------------------------------------
*/

$progress = max(0, min(100, (int) ($course['progress'] ?? 0)));
$cover = $course['banner_image'] ?? base_url('assets/images/placeholder/course-tech-1-card.jpg');
$progressLabel = $isInstructor ? 'Class progress' : 'Your progress';
$titleId = 'course-title-' . (int) $course['offering_id'];
?>

<a
    href="<?= site_url($userRole . '/courses/' . $course['offering_id'] . '/' . $course['slug']) ?>"
    aria-labelledby="<?= $titleId ?>"
    class="group flex h-full flex-col overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-sm
           transition duration-200 ease-out
           hover:-translate-y-0.5 hover:border-[#0040A6]/40 hover:shadow-lg
           active:translate-y-0 active:scale-[0.99]
           focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#0040A6]/55 focus-visible:ring-offset-2
           motion-reduce:transition-none motion-reduce:hover:translate-y-0 motion-reduce:active:scale-100">

    <img
        src="<?= esc($cover, 'attr') ?>"
        alt=""
        loading="lazy"
        decoding="async"
        class="h-32 w-full object-cover">

    <div class="flex flex-1 flex-col gap-4 p-5">

        <div class="flex flex-col gap-1">

            <span class="text-xs font-semibold uppercase tracking-wide text-[#646464]">
                <?= esc($course['course_code']) ?> · <?= esc($course['section_code']) ?>
            </span>

            <div class="flex items-start justify-between gap-3">
                <span id="<?= $titleId ?>" class="line-clamp-2 min-h-14 text-xl font-bold leading-7 text-[#1A1C1E]">
                    <?= esc($course['course_title']) ?>
                </span>
                <svg class="mt-1.5 h-5 w-5 shrink-0 text-[#767676] transition group-hover:translate-x-0.5 group-hover:text-[#0040A6]" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7" /></svg>
            </div>

        </div>

        <div class="flex flex-col gap-0.5">
            <span class="text-sm font-medium text-[#1A1C1E]"><?= esc($course['schedule'] ?? '' ?: 'No schedule set') ?></span>
            <span class="text-xs text-[#646464]"><?= esc($course['room'] ?? '--') ?></span>
            <?php if (!empty($course['instructor_name'])): ?>
                <span class="text-xs text-[#646464]">
                    Instr. <?= esc($course['instructor_name']) ?><?php if ((int) ($course['instructor_count'] ?? 1) > 1): ?> +<?= (int) $course['instructor_count'] - 1 ?> more<?php endif; ?>
                </span>
            <?php elseif (array_key_exists('instructor_count', $course)): ?>
                <span class="text-xs text-[#646464]">No instructor assigned</span>
            <?php endif; ?>
        </div>

        <div class="mt-auto flex flex-col gap-1.5 border-t border-gray-100 pt-4">
            <div class="flex justify-between text-xs text-[#646464]">
                <span><?= $progressLabel ?></span>
                <span class="font-semibold text-[#1A1C1E]"><?= $progress ?>%</span>
            </div>
            <div
                class="h-1.5 overflow-hidden rounded-full bg-gray-200"
                role="progressbar"
                aria-label="<?= $progressLabel ?>"
                aria-valuemin="0"
                aria-valuemax="100"
                aria-valuenow="<?= $progress ?>">
                <div class="h-full rounded-full bg-[#0040A6]" style="width: <?= $progress ?>%;"></div>
            </div>
        </div>

    </div>

</a>
