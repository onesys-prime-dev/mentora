<?php
/*
|--------------------------------------------------------------------------
| Schedule — static weekly calendar grid
|--------------------------------------------------------------------------
| Day columns x time rows, built once per request from ScheduleService's
| grid math (grid.total_rows / grid.hour_marks / grid.by_day, each class
| carrying column + row_start + row_span). No JS: this is one fixed
| picture of a person's week, not the Calendar feature's switchable views.
|
| Two layers share the same CSS grid tracks: faint background cells drawn
| for every (day, row) pair give the classic timetable gridlines, and the
| actual class blocks are placed on top of them with their own explicit
| grid-row/grid-column — the two layers don't need to agree on how many
| cells exist, only on the same row/column numbering.
*/

$modeTone = static fn (string $mode) => match ($mode) {
    'Online' => 'bg-[#E0E5F2] text-brand-dark border-[#A7B4D9]',
    'Hybrid' => 'bg-[#FFF1D6] text-[#946200] border-[#E8C778]',
    default  => 'bg-emerald-50 text-emerald-700 border-emerald-200',
};

$totalRows = $grid['total_rows'];

// "80px" time-rail + 7 equal day columns.
$gridTemplateColumns = '80px repeat(7, minmax(0, 1fr))';
?>

<?= $this->extend('layouts/app') ?>

<?= $this->section('content') ?>

<div class="py-3">

    <?= $this->include('components/back') ?>

    <div class="flex flex-col gap-6 rounded-3xl bg-white p-5 shadow-sm sm:p-8">

        <div class="flex flex-wrap items-center justify-between gap-3">

            <div class="flex items-center gap-4">
                <?= icon('clock', 'h-6 w-6') ?>
                <span class="text-2xl font-bold text-brand">
                    My Schedule
                </span>
            </div>

            <span class="text-sm font-semibold text-[#646464]">
                <?= esc($todayLabel) ?>
            </span>

        </div>

        <?php if (! $hasAnyCourses): ?>

            <div class="flex flex-col items-center justify-center gap-1 py-16 text-center">
                <span class="text-sm font-bold text-[#646464]">
                    <?= match ($role) {
                        'instructor'  => "You aren't teaching any sections yet",
                        'chairperson' => "There are no sections in your program yet",
                        default       => "You aren't enrolled in any courses yet",
                    } ?>
                </span>
                <span class="text-xs text-[#878787]">Your weekly timetable will show up here once that changes.</span>
            </div>

        <?php elseif (! $hasAnyClasses): ?>

            <div class="flex flex-col items-center justify-center gap-1 py-16 text-center">
                <span class="text-sm font-bold text-[#646464]">No meeting times set yet</span>
                <span class="text-xs text-[#878787]">
                    <?= $role !== 'student'
                        ? "Your sections don't have a schedule set. Add one from the course's overview."
                        : "Your courses don't have a schedule set yet." ?>
                </span>
            </div>

        <?php else: ?>

            <div class="overflow-x-auto">

                <div class="min-w-[720px]">

                    <!-- Day header row -->
                    <div class="grid" style="grid-template-columns: <?= esc($gridTemplateColumns) ?>;">

                        <div></div>

                        <?php foreach ($days as $day): ?>
                            <div class="flex flex-col items-center gap-0.5 border-b border-[#A9A9A9]/30 pb-3 <?= $day === $today ? 'text-brand' : 'text-[#646464]' ?>">
                                <span class="text-xs font-bold uppercase tracking-wide"><?= esc(substr($dayLabels[$day], 0, 3)) ?></span>
                                <?php if ($day === $today): ?>
                                    <span class="h-1.5 w-1.5 rounded-full bg-brand"></span>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>

                    </div>

                    <!-- Time grid -->
                    <div
                        class="relative grid"
                        style="grid-template-columns: <?= esc($gridTemplateColumns) ?>; grid-template-rows: repeat(<?= (int) $totalRows ?>, 1.75rem);">

                        <!-- Hour labels, left rail -->
                        <?php foreach ($grid['hour_marks'] as $mark): ?>
                            <div
                                class="relative -top-2 pr-2 text-right text-[11px] text-[#A9A9A9]"
                                style="grid-column: 1; grid-row: <?= (int) $mark['row'] ?>;">
                                <?= esc($mark['label']) ?>
                            </div>
                        <?php endforeach; ?>

                        <!-- Background cells: hour gridlines across every day column -->
                        <?php for ($row = 1; $row <= $totalRows; $row++): ?>
                            <?php foreach ($days as $i => $day): ?>
                                <div
                                    class="<?= $row % 2 === 1 ? 'border-t border-[#A9A9A9]/20' : '' ?> <?= $day === $today ? 'bg-brand/5' : '' ?>"
                                    style="grid-column: <?= $i + 2 ?>; grid-row: <?= $row ?>;"></div>
                            <?php endforeach; ?>
                        <?php endfor; ?>

                        <!-- Class blocks -->
                        <?php foreach ($grid['by_day'] as $classes): ?>
                            <?php foreach ($classes as $class): ?>

                                <?php $tone = $modeTone($class['mode']); ?>

                                <a
                                    href="<?= esc($class['url']) ?>"
                                    class="group m-0.5 flex flex-col justify-between overflow-hidden rounded-lg border px-2 py-1 text-[11px] leading-tight transition hover:shadow-sm <?= $tone ?>"
                                    style="grid-column: <?= (int) $class['column'] + 1 ?>; grid-row: <?= (int) $class['row_start'] ?> / span <?= (int) $class['row_span'] ?>;">

                                    <div class="flex flex-col">
                                        <span class="truncate font-bold group-hover:underline">
                                            <?= esc($class['course_code']) ?> - <?= esc($class['section_code']) ?>
                                        </span>
                                        <span class="truncate opacity-80"><?= esc($class['time']) ?></span>
                                    </div>

                                    <?php if ($class['room'] !== '' && $class['row_span'] > 2): ?>
                                        <span class="truncate opacity-70"><?= esc($class['room']) ?></span>
                                    <?php endif; ?>

                                </a>

                            <?php endforeach; ?>
                        <?php endforeach; ?>

                    </div>

                </div>

            </div>

            <!-- Legend -->
            <div class="flex items-center gap-4 text-xs text-[#878787]">
                <span class="flex items-center gap-1.5">
                    <span class="h-2.5 w-2.5 rounded border <?= $modeTone('On-site') ?>"></span>
                    On-site
                </span>
                <span class="flex items-center gap-1.5">
                    <span class="h-2.5 w-2.5 rounded border <?= $modeTone('Hybrid') ?>"></span>
                    Hybrid
                </span>
                <span class="flex items-center gap-1.5">
                    <span class="h-2.5 w-2.5 rounded border <?= $modeTone('Online') ?>"></span>
                    Online
                </span>
            </div>

        <?php endif; ?>

    </div>

</div>

<?= $this->endSection() ?>
