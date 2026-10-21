<?php
/**
 * One course of the child: its code, title and teacher, and the standing the
 * student sees on their own Grades tab (percentage, band, "Final" or "So far",
 * and the tag). A past course also says how it ended. No links yet: the
 * course pages for a parent come in a later branch.
 *
 * @var array<string, mixed> $course one card of ParentDashboardService
 * @var bool                 $past   whether it belongs to "Past terms"
 */
helper('grade');

$past   = $past ?? false;
$look   = grade_tag_look((string) $course['tag']);
$ending = match ($course['enrollment_status']) {
    'dropped'   => 'Dropped',
    'withdrawn' => 'Withdrawn',
    default     => 'Completed',
};
?>

<article class="flex flex-col gap-3 rounded-2xl bg-white p-5 shadow-sm" data-parent-course="<?= (int) $course['offering_id'] ?>">

    <header class="flex flex-col gap-0.5">
        <span class="text-xs font-semibold uppercase tracking-wide text-[#646464]"><?= esc($course['course_code'] . '-' . $course['section_code']) ?></span>
        <h3 class="text-base font-bold text-[#1A1C1E]"><?= esc($course['course_title']) ?></h3>
        <?php if ($course['instructor_name'] !== ''): ?>
            <span class="text-sm text-[#646464]"><?= esc($course['instructor_name']) ?></span>
        <?php endif; ?>
        <?php if ($past): ?>
            <span class="text-xs text-[#646464]"><?= esc($course['term_name']) ?></span>
        <?php endif; ?>
    </header>

    <?php if ($course['graded']): ?>
        <p class="flex flex-wrap items-baseline gap-x-3 gap-y-1">
            <span class="font-display text-2xl font-bold text-brand"><?= esc(grade_percent($course['percentage'])) ?></span>
            <?php if ($course['band'] !== null && $course['band'] !== ''): ?>
                <span class="text-sm font-semibold text-[#1A1C1E]"><?= esc((string) $course['band']) ?></span>
            <?php endif; ?>
            <span class="text-xs text-[#646464]"><?= $course['is_final'] ? 'Final' : 'So far' ?></span>
        </p>
    <?php else: ?>
        <p class="text-sm text-[#646464]">Not graded yet</p>
    <?php endif; ?>

    <div class="flex flex-wrap items-center gap-2">
        <?= view('components/status-badge', ['label' => (string) $course['tag'], 'tone' => $look['tone'], 'icon' => $look['icon']], ['saveData' => false]) ?>
        <?php if ($past): ?>
            <?= view('components/status-badge', ['label' => $ending, 'tone' => 'neutral'], ['saveData' => false]) ?>
        <?php endif; ?>
    </div>
</article>
