<?php
/**
 * The institution's reports.
 *
 * @var array<string, mixed>                        $reportData
 * @var array<string, mixed>                        $reportOptions
 * @var \App\Services\Courses\MonitorProgramFilters $reportFilters
 * @var ?int                                        $reportDepartment
 * @var ?int                                        $reportProgram
 * @var string                                      $reportSort
 * @var string                                      $reportDir
 * @var array<string, string>                       $reportExports
 */
$base = 'institution/reports';
$f    = $reportFilters;
$d    = $reportData;

$second = 'rounded-full border border-brand bg-white px-4 py-1.5 text-sm font-semibold text-brand transition hover:bg-brand-tint focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/50';

$filtered = $f->isFiltered() || $reportDepartment !== null || $reportProgram !== null;

$query = array_filter(array_merge($f->toQuery(), ['department' => $reportDepartment, 'program' => $reportProgram]), static fn ($v): bool => $v !== null && $v !== '');

$sortUrl = static fn (string $column, string $next): string => site_url($base) . '?' . http_build_query(array_merge($query, ['sort' => $column, 'dir' => $next]));

$termOptions = ['' => 'Any term'];

foreach ($reportOptions['terms'] as $t) {
    $termOptions[(string) $t['id']] = $t['name'] . ' ' . $t['academic_year'];
}

$departmentOptions = ['' => 'Any department'];

foreach ($reportOptions['departments'] as $dep) {
    $departmentOptions[(string) $dep['id']] = $dep['name'];
}

$programOptions = ['' => 'Any program'];

foreach ($reportOptions['programs'] as $p) {
    if ($reportDepartment === null || (int) $p['department_id'] === $reportDepartment) {
        $programOptions[(string) $p['id']] = $p['code'] . ' · ' . $p['name'];
    }
}

$courseRows = [];

foreach ($d['comparison'] as $c) {
    $courseRows[] = [
        'id'    => $c['offering_id'],
        'label' => $c['course_code'] . ' ' . $c['section_code'],
        'cells' => [
            'course_code'  => ['html' => '<a class="font-semibold text-brand hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/50 rounded" href="' . esc(site_url($base . '/offerings/' . $c['offering_id']) . ($f->isFiltered() ? '?' . http_build_query($f->toQuery()) : '')) . '">'
                . esc($c['course_code']) . ' ' . esc($c['section_code']) . '</a><span class="block text-xs text-[#646464]">' . esc($c['course_title']) . '</span>'],
            'program'      => $c['program_name'],
            'instructor'   => $c['instructor_name'] ?? 'No instructor',
            'enrolled'     => (string) $c['enrolled'],
            'completion_percentage' => $c['completion_percentage'] === null ? '—' : $c['completion_percentage'] . '%',
            'average_percentage'    => $c['average_percentage'] === null ? '—' : $c['average_percentage'] . '%',
            'avg_grading_turnaround_hours' => $c['avg_grading_turnaround_hours'] === null ? '—' : $c['avg_grading_turnaround_hours'] . ' h',
        ],
    ];
}

$riskRows = [];

foreach (array_slice($d['atRisk'], 0, 25) as $i => $s) {
    $riskRows[] = [
        'id'    => $i,
        'label' => $s['name'],
        'cells' => [
            'name'   => $s['name'],
            'course' => ['html' => '<a class="font-semibold text-brand hover:underline rounded focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/50" href="' . esc(site_url($base . '/offerings/' . $s['offering_id'])) . '">' . esc($s['course_code']) . ' ' . esc($s['section_code']) . '</a>'],
            'why'    => ['html' => view('components/status-badge', ['label' => $s['reason'] === 'inactive' ? 'No recent activity' : 'Behind', 'tone' => $s['reason'] === 'inactive' ? 'danger' : 'warning'], ['saveData' => false])],
            'progress' => $s['progress'] . '%',
            'last'   => $s['days_since'] === null ? 'Never' : $s['days_since'] . ' days ago',
        ],
    ];
}

$maxBucket = max(1, ...array_values($d['distribution']));
$gradedAny = array_sum($d['distribution']) > 0;
?>

<?= $this->extend('layouts/app') ?>

<?= $this->section('content') ?>

<div class="py-3">

<?php // The way back stays on the background, above the panel, not in the box. ?>
<?= view('components/back-link', ['backUrl' => site_url('institution/dashboard'), 'backLabel' => 'Back to home'], ['saveData' => false]) ?>

<div class="flex flex-col gap-6 rounded-3xl bg-white p-5 shadow-sm sm:p-8" data-page-panel>

    <?= view('components/page-head', [
        'title'       => 'Reports',
        'icon'        => 'score',
        'description' => 'How your courses are doing: enrolment, grades, grading and who needs attention.',
        'actions'     => implode(' ', array_map(
            static fn (string $kind, string $label): string => '<a class="' . $second . '" href="' . esc(site_url($base . '/export/' . $kind) . ($query === [] ? '' : '?' . http_build_query($query))) . '" data-export="' . esc($kind) . '">' . esc($label) . ' (CSV)</a>',
            array_keys($reportExports),
            array_values($reportExports),
        )),
    ], ['saveData' => false]) ?>

    <?= view('components/toolbar', [
        'surface'  => 'tinted', // on the page's white panel a white card would not stand out
        'action'   => site_url($base),
        'filtered' => $filtered,
        'fields'   => [
            ['type' => 'select', 'name' => 'term', 'label' => 'Term', 'options' => $termOptions, 'value' => $f->termId !== null ? (string) $f->termId : ''],
            ['type' => 'select', 'name' => 'department', 'label' => 'Department', 'options' => $departmentOptions, 'value' => $reportDepartment !== null ? (string) $reportDepartment : ''],
            ['type' => 'select', 'name' => 'program', 'label' => 'Program', 'options' => $programOptions, 'value' => $reportProgram !== null ? (string) $reportProgram : '', 'attrs' => ['data-programs' => json_encode($reportOptions['programs'], JSON_UNESCAPED_UNICODE)]],
            ['type' => 'date', 'name' => 'from', 'label' => 'Activity from', 'value' => (string) $f->from],
            ['type' => 'date', 'name' => 'to', 'label' => 'Activity to', 'value' => (string) $f->to],
        ],
    ], ['saveData' => false]) ?>

    <?php if ($d['rows'] === []): ?>

        <div class="rounded-2xl border border-gray-200 bg-gray-50">
            <?= view('components/empty-state', [
                'icon'  => 'score',
                'title' => $filtered ? 'No courses match these filters' : 'No courses to report on yet',
                'text'  => $filtered ? 'Try a different term, department or program.' : 'Reports fill in as offerings, enrolments and grades are added.',
            ], ['saveData' => false]) ?>
        </div>

    <?php else: ?>

        <div class="grid grid-cols-2 gap-4 lg:grid-cols-5" data-report-totals>
            <?= view('components/metric-card', ['label' => 'Courses', 'value' => (string) $d['snapshot']['courses'], 'icon' => 'hat', 'tone' => 'neutral'], ['saveData' => false]) ?>
            <?= view('components/metric-card', ['label' => 'Instructors', 'value' => (string) $d['snapshot']['instructors'], 'icon' => 'user', 'tone' => 'neutral'], ['saveData' => false]) ?>
            <?= view('components/metric-card', ['label' => 'Students', 'value' => number_format($d['snapshot']['students']), 'icon' => 'user', 'tone' => 'neutral'], ['saveData' => false]) ?>
            <?= view('components/metric-card', ['label' => 'Need attention', 'value' => (string) count($d['atRisk']), 'icon' => 'attention', 'tone' => $d['atRisk'] === [] ? 'success' : 'warning', 'detail' => 'No recent activity, or behind'], ['saveData' => false]) ?>
            <?= view('components/metric-card', ['label' => 'Graded', 'value' => $d['grading']['percent'] === null ? '—' : $d['grading']['percent'] . '%', 'icon' => 'grades', 'tone' => 'neutral', 'detail' => $d['grading']['turnaround'] === null ? 'Published grades only' : 'Typically ' . $d['grading']['turnaround'] . ' h to grade'], ['saveData' => false]) ?>
        </div>

        <section class="rounded-2xl border border-gray-200 bg-gray-50 p-6 shadow-md" aria-labelledby="distribution" data-report-distribution>
            <div class="flex flex-wrap items-baseline justify-between gap-2">
                <h2 id="distribution" class="text-sm font-bold text-brand">Grade distribution</h2>
                <?php if ($d['grading']['average'] !== null): ?>
                    <span class="text-xs text-[#646464]">Average <?= esc((string) $d['grading']['average']) ?>%</span>
                <?php endif; ?>
            </div>

            <?php if (! $gradedAny): ?>
                <p class="mt-3 text-sm text-[#646464]">No published grades yet in these courses.</p>
            <?php else: ?>
                <ul class="mt-4 flex flex-col gap-2">
                    <?php foreach ($d['distribution'] as $bucket => $count): ?>
                        <li class="flex items-center gap-3 text-sm">
                            <span class="w-16 shrink-0 text-xs font-semibold text-[#646464]"><?= esc((string) $bucket) ?></span>
                            <span class="h-3 flex-1 overflow-hidden rounded-full bg-gray-100" role="presentation">
                                <span class="block h-full rounded-full bg-brand" style="width: <?= (int) round($count / $maxBucket * 100) ?>%"></span>
                            </span>
                            <span class="w-10 shrink-0 text-right text-xs font-semibold text-[#1A1C1E]" data-bucket="<?= esc((string) $bucket) ?>"><?= esc((string) $count) ?></span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </section>

        <section class="flex flex-col gap-3" aria-labelledby="courses-heading">
            <h2 id="courses-heading" class="text-sm font-bold text-brand">Courses compared</h2>

            <?= view('components/data-table', [
                'caption' => 'Courses compared',
                'columns' => [
                    ['key' => 'course_code', 'label' => 'Course', 'sortable' => true],
                    ['key' => 'program', 'label' => 'Program'],
                    ['key' => 'instructor', 'label' => 'Instructor'],
                    ['key' => 'enrolled', 'label' => 'Enrolled', 'sortable' => true],
                    ['key' => 'completion_percentage', 'label' => 'Completion', 'sortable' => true],
                    ['key' => 'average_percentage', 'label' => 'Average grade', 'sortable' => true],
                    ['key' => 'avg_grading_turnaround_hours', 'label' => 'Grading time', 'sortable' => true],
                ],
                'rows'    => $courseRows,
                'sort'    => $reportSort,
                'dir'     => $reportDir,
                'sortUrl' => $sortUrl,
            ], ['saveData' => false]) ?>
        </section>

        <section class="flex flex-col gap-3" aria-labelledby="risk-heading" data-report-at-risk>
            <div class="flex flex-wrap items-baseline justify-between gap-2">
                <h2 id="risk-heading" class="text-sm font-bold text-brand">Students who need attention</h2>
                <?php if (count($d['atRisk']) > 25): ?>
                    <span class="text-xs text-[#646464]">The 25 who have been silent longest, of <?= esc((string) count($d['atRisk'])) ?>. The export has everyone.</span>
                <?php endif; ?>
            </div>

            <?= view('components/data-table', [
                'caption'    => 'Students who need attention',
                'columns'    => [
                    ['key' => 'name', 'label' => 'Student'],
                    ['key' => 'course', 'label' => 'Course'],
                    ['key' => 'why', 'label' => 'Why'],
                    ['key' => 'progress', 'label' => 'Progress'],
                    ['key' => 'last', 'label' => 'Last activity'],
                ],
                'rows'       => $riskRows,
                'emptyIcon'  => 'check',
                'emptyTitle' => 'Nobody needs attention',
                'emptyText'  => 'Every student in these courses has been active and is keeping up.',
            ], ['saveData' => false]) ?>
        </section>

    <?php endif; ?>

</div>

</div>

<?= $this->endSection() ?>
