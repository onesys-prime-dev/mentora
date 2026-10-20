<?php
/**
 * One student's grade report. Data: GradeReportService::studentReport() as
 * $report; $printBar shows the Print button on screen (not in the PDF);
 * $showClassMean adds the class average (for the instructor, not the student).
 */
$f = static fn ($v) => $v === null ? '—' : rtrim(rtrim(number_format((float) $v, 2, '.', ''), '0'), '.');
$student = $report['student'];
$offering = $report['offering'];
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Grade report: <?= esc($student['student_name']) ?></title>
    <?= view('pages/courses/features/grades/reports/_style', [], ['saveData' => false]) ?>
</head>
<body>

<?php if (!empty($printBar)): ?>
    <div class="printbar"><button type="button" onclick="window.print()">Print or save as PDF</button></div>
<?php endif; ?>

<h1>Grade report</h1>
<p class="muted">
    <b style="color:#1A1C1E"><?= esc($student['student_name']) ?></b>
    <?php if ($student['student_number'] !== ''): ?>(<?= esc($student['student_number']) ?>)<?php endif; ?>
    <br>
    <?= esc($offering['course_code']) ?> <?= esc($offering['course_title']) ?>
    · Section <?= esc($offering['section']) ?>
    <?php if ($offering['term'] !== ''): ?>· <?= esc($offering['term']) ?><?php endif; ?>
    <br>
    <span class="small">Generated <?= esc(date('M j, Y g:i A', strtotime($report['generated_at']))) ?></span>
</p>

<table style="margin-top:10px">
    <tr>
        <td class="stat"><b><?= $student['percentage'] !== null ? esc($f($student['percentage'])) . '%' : '—' ?></b><?= $student['is_final'] ? 'Final grade' : 'Grade so far' ?></td>
        <td class="stat"><b><?= esc((string) ($student['band'] ?? '—')) ?></b>Mark</td>
        <td class="stat"><b><?= esc($student['tag']) ?></b>Standing</td>
        <?php if (!empty($showClassMean)): ?>
            <td class="stat"><b><?= esc($f($report['class_mean'])) ?>%</b>Class average</td>
        <?php endif; ?>
    </tr>
</table>

<?php if (!$student['is_final']): ?>
    <p class="note">These grades are not final yet. They count only the work graded so far, and can still change.</p>
<?php endif; ?>

<?php if ($student['notes'] !== []): ?>
    <p class="small muted" style="margin-top:8px"><?= esc(implode(' · ', $student['notes'])) ?></p>
<?php endif; ?>

<h2>How the grade is made up</h2>
<table>
    <thead><tr><th>Category</th><th class="num">Weight</th><th class="num">Points</th><th class="num">Percentage</th></tr></thead>
    <tbody>
        <?php foreach ($report['categories'] as $category): ?>
            <tr>
                <td><?= esc($category['name']) ?></td>
                <td class="num"><?= esc($f($category['weight'])) ?>%</td>
                <td class="num"><?= $category['possible'] > 0 ? esc($f($category['earned'])) . ' / ' . esc($f($category['possible'])) : '—' ?></td>
                <td class="num"><?= $category['percentage'] !== null ? esc($f($category['percentage'])) . '%' : 'Nothing graded yet' ?></td>
            </tr>
        <?php endforeach; ?>
    </tbody>
</table>

<h2>Scores</h2>
<table>
    <thead><tr><th>Item</th><th>Category</th><th class="num">Score</th></tr></thead>
    <tbody>
        <?php foreach ($report['items'] as $item): ?>
            <tr>
                <td><?= esc($item['name']) ?><?= $item['curved'] ? ' <span class="small muted">(adjusted)</span>' : '' ?></td>
                <td><?= esc($item['category']) ?></td>
                <td class="num">
                    <?php if ($item['score'] === 'EX'): ?>Excused
                    <?php elseif ($item['score'] === null): ?>Not graded yet
                    <?php else: ?><?= esc($f($item['score'])) ?> / <?= esc($f($item['max'])) ?><?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
    </tbody>
</table>

</body>
</html>
