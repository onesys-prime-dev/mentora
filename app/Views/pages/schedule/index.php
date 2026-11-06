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
    'Online' => 'bg-[#E0E5F2] text-brand-dark',
    'Hybrid' => 'bg-[#FFEFD0] text-[#6B4400]',
    default  => 'bg-[#DDF3EC] text-[#0B4D3B]',
};

$totalRows = $grid['total_rows'];

// "80px" time-rail + 7 equal day columns.
$gridTemplateColumns = '80px repeat(7, minmax(0, 1fr))';
?>

<?= $this->extend('layouts/app') ?>

<?= $this->section('content') ?>

<div class="flex flex-col py-3 lg:h-full lg:min-h-0">

    <?= $this->include('components/back') ?>

    <div class="flex flex-col gap-6 rounded-3xl bg-white p-5 shadow-sm sm:p-8 sm:pb-4 lg:min-h-0 lg:flex-1">

        <div class="flex flex-wrap items-center justify-between gap-3">

            <div class="flex items-center gap-4">
                <?= icon('clock', 'h-6 w-6') ?>
                <div class="flex flex-col">
                    <span class="text-2xl font-bold text-brand">
                        My Schedule
                    </span>
                    <span class="text-xs text-[#5B6577]">
                        <?= esc($weekLabel) ?>
                        <?php if ($hasAnyClasses): ?>
                            · <?= (int) $sessionCount ?> <?= $sessionCount === 1 ? 'session' : 'sessions' ?>
                            · <?= esc(rtrim(rtrim(number_format($classHours, 1), '0'), '.')) ?> hrs of class
                        <?php endif; ?>
                    </span>
                </div>
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

            <div class="flex flex-col overflow-auto lg:min-h-0 lg:flex-1 [scrollbar-width:none] [&::-webkit-scrollbar]:hidden">

                <div class="flex min-w-180 flex-1 flex-col">

                    <!-- Day header row -->
                    <div class="sticky top-0 z-10 grid bg-white" style="grid-template-columns: <?= esc($gridTemplateColumns) ?>;">

                        <div></div>

                        <?php foreach ($days as $day): ?>
                            <div class="flex flex-col items-center gap-1 border-b border-[#A9A9A9]/30 pb-2.5 <?= $day === $today ? 'text-brand' : 'text-[#5B6577]' ?>">
                                <span class="text-[11px] font-semibold uppercase tracking-wider"><?= esc(substr($dayLabels[$day], 0, 3)) ?></span>
                                <span class="flex h-8 w-8 items-center justify-center rounded-full text-base font-semibold <?= $day === $today ? 'bg-brand font-bold text-white' : 'text-[#1B2433]' ?>">
                                    <?= (int) $weekDates[$day] ?>
                                </span>
                            </div>
                        <?php endforeach; ?>

                    </div>

                    <!-- Time grid -->
                    <div
                        class="relative grid flex-1 pt-2"
                        style="grid-template-columns: <?= esc($gridTemplateColumns) ?>; grid-template-rows: repeat(<?= (int) $totalRows ?>, minmax(1.75rem, 1fr));">

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

                        <!-- "Now" line across today's column. The server runs in UTC, so the script
                             below places it with the browser's own clock and hides it outside the axis. -->
                        <?php $todayIndex = array_search($today, $days, true); ?>
                        <?php if ($todayIndex !== false): ?>
                            <div
                                class="pointer-events-none relative z-1 hidden"
                                style="grid-column: <?= $todayIndex + 2 ?>; grid-row: 1 / span <?= (int) $totalRows ?>;"
                                aria-hidden="true"
                                data-now-line
                                data-start="<?= (int) $grid['start_minutes'] ?>"
                                data-end="<?= (int) $grid['end_minutes'] ?>">
                                <div class="absolute inset-x-0 h-0.5 bg-brand" data-now-marker>
                                    <span class="absolute -left-1 -top-1 h-2.5 w-2.5 rounded-full bg-brand"></span>
                                </div>
                            </div>
                        <?php endif; ?>

                        <!-- Class blocks -->
                        <?php foreach ($grid['by_day'] as $classes): ?>
                            <?php foreach ($classes as $class): ?>

                                <?php $tone = $modeTone($class['mode']); ?>

                                <a
                                    href="<?= esc($class['url']) ?>"
                                    class="group m-0.5 flex flex-col justify-between overflow-hidden rounded-[10px] px-2.5 py-1.5 text-[11px] leading-tight transition hover:shadow-sm <?= $tone ?>"
                                    style="grid-column: <?= (int) $class['column'] + 1 ?>; grid-row: <?= (int) $class['row_start'] ?> / span <?= (int) $class['row_span'] ?>;">

                                    <div class="flex flex-col">
                                        <span class="truncate text-xs font-bold group-hover:underline">
                                            <?= esc($class['course_code']) ?> – <?= esc($class['section_code']) ?>
                                        </span>
                                        <span class="truncate"><?= esc($class['time']) ?></span>
                                    </div>

                                    <?php if ($class['row_span'] > 2): ?>
                                        <span class="truncate">
                                            <?= esc(trim($class['room'] . ' · ' . $class['mode'], ' ·')) ?>
                                        </span>
                                    <?php endif; ?>

                                </a>

                            <?php endforeach; ?>
                        <?php endforeach; ?>

                    </div>

                </div>

            </div>

            <!-- Legend -->
            <div class="-mt-3 flex items-center gap-3 text-[11px] text-[#878787]">
                <span class="flex items-center gap-1.5">
                    <span class="h-3 w-3 rounded <?= $modeTone('On-site') ?>"></span>
                    On-site
                </span>
                <span class="flex items-center gap-1.5">
                    <span class="h-3 w-3 rounded <?= $modeTone('Hybrid') ?>"></span>
                    Hybrid
                </span>
                <span class="flex items-center gap-1.5">
                    <span class="h-3 w-3 rounded <?= $modeTone('Online') ?>"></span>
                    Online
                </span>
            </div>

        <?php endif; ?>

    </div>

</div>

<script>
(function () {
    var line = document.querySelector('[data-now-line]');
    var marker = line && line.querySelector('[data-now-marker]');

    if (!line || !marker) {
        return;
    }

    var start = Number(line.dataset.start);
    var end = Number(line.dataset.end);

    function place() {
        var now = new Date();
        var minutes = now.getHours() * 60 + now.getMinutes();
        var inside = end > start && minutes >= start && minutes <= end;

        line.classList.toggle('hidden', !inside);

        if (inside) {
            marker.style.top = ((minutes - start) / (end - start) * 100) + '%';
        }
    }

    place();
    setInterval(place, 60000);
})();
</script>

<?= $this->endSection() ?>
