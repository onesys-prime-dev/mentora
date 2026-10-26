<?php
/**
 * One course, opened from the reports.
 *
 * @var array<string, mixed>                        $offeringReport
 * @var \App\Services\Courses\MonitorProgramFilters $reportFilters
 */
// Not called `detail`: view data is shared, and the metric card has a parameter of that name.
$detail = $offeringReport;
$o = $detail['offering'];
$g = $detail['grades'];
$a = $detail['activity'];

$back = site_url('institution/reports') . ($reportFilters->isFiltered() ? '?' . http_build_query($reportFilters->toQuery()) : '');

$riskRows = [];

foreach ($detail['atRisk'] as $i => $s) {
    $riskRows[] = [
        'id'    => $i,
        'label' => $s['name'],
        'cells' => [
            'name'     => $s['name'],
            'why'      => ['html' => view('components/status-badge', ['label' => $s['reason'] === 'inactive' ? 'No recent activity' : 'Behind', 'tone' => $s['reason'] === 'inactive' ? 'danger' : 'warning'], ['saveData' => false])],
            'progress' => $s['progress'] . '%',
            'last'     => $s['days_since'] === null ? 'Never' : $s['days_since'] . ' days ago',
        ],
    ];
}

$maxBucket = $g === null ? 1 : max(1, ...array_values($g['distribution']));
?>

<?= $this->extend('layouts/app') ?>

<?= $this->section('content') ?>

<div class="py-3">

<?php // The way back stays on the background, above the panel, not in the box. ?>
<?= view('components/back-link', ['backUrl' => $back, 'backLabel' => 'Back to reports'], ['saveData' => false]) ?>

<div class="flex flex-col gap-6 rounded-3xl bg-white p-5 shadow-sm sm:p-8" data-page-panel>

    <?= view('components/page-head', [
        'title'       => $o['course_code'] . ' ' . $o['section_code'],
        'icon'        => 'hat',
        'description' => $o['course_title'] . ' · ' . $o['program_name'] . ' · ' . $o['term_name'] . ' ' . $o['academic_year'],
    ], ['saveData' => false]) ?>

    <p>
        <a class="inline-flex rounded-full border border-brand bg-white px-5 py-2 text-sm font-semibold text-brand transition hover:bg-brand-tint focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/50" data-view-course
           href="<?= esc(site_url('institution/courses/' . $o['offering_id'] . '/' . url_title((string) $o['course_title'], '-', true) . '/overview')) ?>">Open the course (view only)</a>
    </p>

    <p class="text-sm text-[#3D3D3D]" data-report-instructors>
        <?= $detail['instructors'] === [] ? 'No instructor is assigned yet.' : 'Taught by ' . esc(implode(', ', $detail['instructors'])) . '.' ?>
    </p>

    <div class="grid grid-cols-2 gap-4 lg:grid-cols-4" data-report-totals>
        <?= view('components/metric-card', ['label' => 'Enrolled', 'value' => (string) $detail['enrolled'], 'detail' => 'Capacity ' . $o['capacity'], 'icon' => 'user', 'tone' => 'neutral'], ['saveData' => false]) ?>
        <?= view('components/metric-card', ['label' => 'Completion', 'value' => $detail['completion'] === null ? '—' : $detail['completion'] . '%', 'icon' => 'check', 'tone' => 'neutral', 'detail' => 'Of the published items'], ['saveData' => false]) ?>
        <?= view('components/metric-card', ['label' => 'Average grade', 'value' => $g === null || $g['average_percentage'] === null ? '—' : $g['average_percentage'] . '%', 'icon' => 'grades', 'tone' => 'neutral', 'detail' => $g === null ? 'Grades not published' : $g['graded_count'] . ' of ' . $g['enrolled'] . ' graded'], ['saveData' => false]) ?>
        <?= view('components/metric-card', ['label' => 'Grading time', 'value' => $a['avg_grading_turnaround_hours'] === null ? '—' : $a['avg_grading_turnaround_hours'] . ' h', 'icon' => 'clock', 'tone' => 'neutral', 'detail' => $a['graded_count'] . ' graded'], ['saveData' => false]) ?>
    </div>

    <?php if ($g !== null && $g['graded_count'] > 0): ?>
        <section class="rounded-2xl border border-gray-200 bg-gray-50 p-6 shadow-md" aria-labelledby="dist" data-report-distribution>
            <h2 id="dist" class="text-sm font-bold text-brand">Grade distribution</h2>
            <ul class="mt-4 flex flex-col gap-2">
                <?php foreach ($g['distribution'] as $bucket => $count): ?>
                    <li class="flex items-center gap-3 text-sm">
                        <span class="w-16 shrink-0 text-xs font-semibold text-[#646464]"><?= esc((string) $bucket) ?></span>
                        <span class="h-3 flex-1 overflow-hidden rounded-full bg-gray-100" role="presentation">
                            <span class="block h-full rounded-full bg-brand" style="width: <?= (int) round($count / $maxBucket * 100) ?>%"></span>
                        </span>
                        <span class="w-10 shrink-0 text-right text-xs font-semibold" data-bucket="<?= esc((string) $bucket) ?>"><?= esc((string) $count) ?></span>
                    </li>
                <?php endforeach; ?>
            </ul>
        </section>
    <?php endif; ?>

    <section class="rounded-2xl border border-gray-200 bg-gray-50 p-6 shadow-md" aria-labelledby="activity-heading">
        <h2 id="activity-heading" class="text-sm font-bold text-brand">Instructor activity</h2>
        <p class="mt-2 text-sm text-[#3D3D3D]" data-report-activity>
            <?= esc((string) $a['content_updates']) ?> content update<?= $a['content_updates'] === 1 ? '' : 's' ?> and
            <?= esc((string) $a['announcements']) ?> announcement<?= $a['announcements'] === 1 ? '' : 's' ?><?= $reportFilters->from !== null || $reportFilters->to !== null ? ' in the chosen dates' : '' ?>.
        </p>
    </section>

    <section class="flex flex-col gap-3" aria-labelledby="risk-heading" data-report-at-risk>
        <h2 id="risk-heading" class="text-sm font-bold text-brand">Students who need attention</h2>

        <?= view('components/data-table', [
            'caption'    => 'Students who need attention in ' . $o['course_code'],
            'columns'    => [
                ['key' => 'name', 'label' => 'Student'],
                ['key' => 'why', 'label' => 'Why'],
                ['key' => 'progress', 'label' => 'Progress'],
                ['key' => 'last', 'label' => 'Last activity'],
            ],
            'rows'       => $riskRows,
            'emptyIcon'  => 'check',
            'emptyTitle' => 'Nobody needs attention',
            'emptyText'  => 'Every student has been active and is keeping up.',
        ], ['saveData' => false]) ?>
    </section>

</div>

</div>

<?= $this->endSection() ?>
