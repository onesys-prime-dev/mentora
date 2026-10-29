<?php
/**
 * The dean's department report as one page to print or save as a PDF: Monitor Department's totals and
 * comparison, the teaching load and where sign-off stands. Plain tables, so it prints cleanly; the bar at
 * the top is for the screen only. Data: DepartmentReportService::build() as $report.
 *
 * @var array<string, mixed> $report
 */
use App\Services\Courses\Grades\GradeSignoffService;

$pct = static fn ($v): string => $v === null ? '—' : rtrim(rtrim(number_format((float) $v, 1, '.', ''), '0'), '.') . '%';

$hours = static function ($h): string {
    if ($h === null) {
        return '—';
    }

    return (float) $h < 24 ? rtrim(rtrim(number_format((float) $h, 1, '.', ''), '0'), '.') . ' h' : rtrim(rtrim(number_format((float) $h / 24, 1, '.', ''), '0'), '.') . ' days';
};

$day = static fn (?string $at): string => $at === null || $at === '' ? '—' : date('M j, Y', strtotime($at));

$units = static fn ($v): string => rtrim(rtrim(number_format((float) $v, 1, '.', ''), '0'), '.');

$monitor  = $report['monitor'];
$totals   = $monitor['totals'];
$load     = $report['teaching_load'];
$signoff  = $report['signoff'];
$byProgram = $monitor['compare'] === 'program';

$query = http_build_query((array) service('request')->getGet());
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= esc($report['department']) ?> department report</title>
    <?= view('pages/courses/features/grades/reports/_style', [], ['saveData' => false]) ?>
    <style>
        section { break-inside: avoid-page; }
        tr { break-inside: avoid; }
        .back { margin-left: 10px; color: #0040A6; font-size: 12px; }
    </style>
</head>
<body>

<div class="printbar">
    <button type="button" onclick="window.print()">Print or save as PDF</button>
    <a class="back" href="<?= esc(site_url('dean/monitor-department') . ($query === '' ? '' : '?' . $query), 'attr') ?>">Back to Monitor Department</a>
</div>

<h1><?= esc($report['department']) ?>: department report</h1>
<p class="muted small">
    Generated <?= esc(date('M j, Y g:i A', strtotime($report['generated_at']))) ?>
    <?php if ($report['filters'] !== []): ?>· <?= esc(implode(' · ', $report['filters'])) ?><?php endif; ?>
</p>

<section data-part="monitor">
    <h2>Monitor Department</h2>

    <?php if ((int) ($totals['courses'] ?? 0) === 0): ?>
        <p class="muted">No courses match these filters.</p>
    <?php else: ?>
        <table style="margin-bottom:8px">
            <tr>
                <td class="stat"><b><?= esc(number_format((int) $totals['students'])) ?></b>Students (<?= esc(number_format((int) $totals['courses'])) ?> courses, <?= esc(number_format((int) $totals['instructors'])) ?> instructors)</td>
                <td class="stat"><b><?= esc($pct($totals['completion'])) ?></b>Completion</td>
                <td class="stat"><b><?= esc($pct($totals['average_grade'])) ?></b>Average grade</td>
                <td class="stat"><b><?= esc((string) $totals['at_risk']) ?></b>Students at risk</td>
            </tr>
        </table>

        <table>
            <thead>
                <tr>
                    <th><?= $byProgram ? 'Program' : 'Sub-department' ?></th>
                    <th class="num">Students</th>
                    <th class="num">Completion</th>
                    <th class="num">Avg. grade</th>
                    <th class="num">Graded</th>
                    <th class="num">Turnaround</th>
                    <th class="num">At risk</th>
                    <th>Last activity</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($monitor['groups'] as $g): ?>
                    <tr>
                        <td><?= esc((string) $g['name']) ?><?= $byProgram && ! empty($g['context']) ? '<br><span class="muted small">' . esc((string) $g['context']) . '</span>' : '' ?></td>
                        <td class="num"><?= esc(number_format((int) $g['students'])) ?></td>
                        <td class="num"><?= esc($pct($g['completion'])) ?></td>
                        <td class="num"><?= esc($pct($g['average_grade'])) ?></td>
                        <td class="num"><?= esc($pct($g['grading_progress'])) ?></td>
                        <td class="num"><?= esc($hours($g['turnaround'])) ?></td>
                        <td class="num"><?= esc((string) $g['at_risk']) ?></td>
                        <td><?= esc($day($g['last_activity'])) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</section>

<section data-part="teaching-load">
    <h2>Teaching load<?= $load['term'] !== null ? ': ' . esc($load['term']) : '' ?></h2>

    <?php if ($load['rows'] === []): ?>
        <p class="muted">No instructors teach in this term.</p>
    <?php else: ?>
        <table>
            <thead>
                <tr>
                    <th>Instructor</th>
                    <th class="num">Sections</th>
                    <th class="num">Students</th>
                    <th class="num">Units</th>
                    <th class="num">Waiting to be graded</th>
                    <th class="num">Turnaround</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($load['rows'] as $r): ?>
                    <tr>
                        <td><?= esc((string) $r['name']) ?></td>
                        <td class="num"><?= esc((string) $r['sections']) ?></td>
                        <td class="num"><?= esc(number_format((int) $r['students'])) ?></td>
                        <td class="num"><?= esc($units($r['units'])) ?></td>
                        <td class="num"><?= esc((string) $r['ungraded']) ?></td>
                        <td class="num"><?= esc($hours($r['turnaround'])) ?></td>
                        <td class="tag"><?= $r['high_load'] ? 'High load' : '' ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <p class="muted small">A high load is <?= (int) $load['high_load_units'] ?> units or more; the institution sets that number.</p>
    <?php endif; ?>
</section>

<section data-part="signoff">
    <h2>Sign-off status<?= $signoff['term'] !== null ? ': ' . esc($signoff['term']) : '' ?></h2>

    <?php if ($signoff['programs'] === []): ?>
        <p class="muted">No sections to sign off.</p>
    <?php else: ?>
        <?php foreach ($signoff['programs'] as $program): ?>
            <p style="margin:10px 0 3px"><b><?= esc((string) $program['name']) ?></b>
                <span class="muted small">
                    <?= (int) $program['counts'][GradeSignoffService::READY] ?> ready ·
                    <?= (int) $program['counts'][GradeSignoffService::BLOCKED] ?> blocked ·
                    <?= (int) $program['counts'][GradeSignoffService::SIGNED_OFF] ?> signed off
                </span>
            </p>
            <table>
                <tbody>
                    <?php foreach ($program['sections'] as $s): ?>
                        <tr>
                            <td><?= esc($s['course_code'] . ' ' . $s['section_code']) ?><br><span class="muted small"><?= esc((string) $s['course_title']) ?></span></td>
                            <td><?= esc((string) ($s['instructor'] ?? 'No instructor assigned')) ?></td>
                            <td class="num"><?= esc((string) $s['students']) ?> students</td>
                            <td>
                                <?php if ($s['state'] === GradeSignoffService::SIGNED_OFF): ?>
                                    <span class="tag">Signed off</span> <?= esc($day($s['signed_at'])) ?><?= $s['signed_by_name'] ? ' by ' . esc((string) $s['signed_by_name']) : '' ?>
                                <?php elseif ($s['state'] === GradeSignoffService::READY): ?>
                                    <span class="tag">Ready</span>
                                <?php else: ?>
                                    <span class="tag">Blocked</span> <span class="muted small"><?= esc(implode(' ', $s['blockers'])) ?></span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endforeach; ?>
    <?php endif; ?>
</section>

</body>
</html>
