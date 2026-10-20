<?php
/**
 * Class summary: the class's grades at a glance, one page to print or file.
 * Data: GradeReportService::classReport() as $report; $printBar shows the
 * Print button on screen (not in the PDF).
 */
$f = static fn ($v) => $v === null ? '—' : rtrim(rtrim(number_format((float) $v, 2, '.', ''), '0'), '.');
$summary = $report['summary'];
$offering = $report['offering'];
$final = $report['finalization'];
$peak = max(1, ...array_column($summary['spread'], 'count'));
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Class summary: <?= esc($offering['course_code']) ?> <?= esc($offering['section']) ?></title>
    <?= view('pages/courses/features/grades/reports/_style', [], ['saveData' => false]) ?>
</head>
<body>

<?php if (!empty($printBar)): ?>
    <div class="printbar"><button type="button" onclick="window.print()">Print or save as PDF</button></div>
<?php endif; ?>

<h1>Class summary</h1>
<p class="muted">
    <?= esc($offering['course_code']) ?> <?= esc($offering['course_title']) ?>
    · Section <?= esc($offering['section']) ?>
    <?php if ($offering['term'] !== ''): ?>· <?= esc($offering['term']) ?><?php endif; ?>
    <br>
    <span class="small">
        Grading: <?= esc($report['scheme']['name']) ?><?= $report['is_published'] ? '' : ' (draft: students do not see these grades yet)' ?>
        · <?= $final ? 'Final grades, ' . esc(date('M j, Y', strtotime((string) $final['finalized_at']))) : 'Running grades (not final yet)' ?>
        · Generated <?= esc(date('M j, Y g:i A', strtotime($report['generated_at']))) ?>
    </span>
</p>

<table style="margin-top:10px">
    <tr>
        <td class="stat"><b><?= esc($f($summary['mean'])) ?>%</b>Class average</td>
        <td class="stat"><b><?= esc($f($summary['median'])) ?>%</b>Median</td>
        <td class="stat"><b><?= esc($f($summary['high'])) ?>% / <?= esc($f($summary['low'])) ?>%</b>Highest / lowest</td>
        <td class="stat"><b><?= (int) $summary['at_or_above_passing'] ?> of <?= (int) $summary['graded'] ?></b>At or above <?= esc($f($summary['passing_mark'])) ?>%</td>
    </tr>
</table>

<?php if ($report['curves'] !== []): ?>
    <p class="note">
        <b>A curve is in force:</b>
        <?php foreach ($report['curves'] as $curve): ?>
            <?= esc($curve['target']) ?>: <?= esc($curve['label']) ?>.
        <?php endforeach; ?>
    </p>
<?php endif; ?>

<h2>Standing</h2>
<table>
    <thead><tr><th>Standing</th><th class="num">Students</th></tr></thead>
    <tbody>
        <?php foreach ($summary['tags'] as $tag => $count): ?>
            <tr><td class="tag"><?= esc($tag) ?></td><td class="num"><?= (int) $count ?></td></tr>
        <?php endforeach; ?>
    </tbody>
</table>

<h2>How the grades are spread</h2>
<table>
    <tbody>
        <?php foreach ($summary['spread'] as $bucket): ?>
            <tr>
                <td style="width:90px"><?= esc($bucket['label']) ?></td>
                <td><div class="bar"><span style="width:<?= (int) round($bucket['count'] / $peak * 100) ?>%"></span></div></td>
                <td class="num" style="width:40px"><?= (int) $bucket['count'] ?></td>
            </tr>
        <?php endforeach; ?>
    </tbody>
</table>

<h2>Students</h2>
<table>
    <thead><tr><th>Student</th><th>Number</th><th class="num">Grade</th><th>Mark</th><th>Standing</th><th>Notes</th></tr></thead>
    <tbody>
        <?php foreach ($report['rows'] as $row): ?>
            <tr>
                <td><?= esc($row['student_name']) ?></td>
                <td><?= esc($row['student_number']) ?></td>
                <td class="num"><?= $row['percentage'] !== null ? esc($f($row['percentage'])) . '%' : '—' ?></td>
                <td><?= esc((string) ($row['band'] ?? '')) ?></td>
                <td class="tag"><?= esc($row['tag']) ?></td>
                <td class="small muted"><?= esc(implode('; ', $row['notes'])) ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if ($report['rows'] === []): ?>
            <tr><td colspan="6" class="muted">No students are enrolled in this section.</td></tr>
        <?php endif; ?>
    </tbody>
</table>

</body>
</html>
