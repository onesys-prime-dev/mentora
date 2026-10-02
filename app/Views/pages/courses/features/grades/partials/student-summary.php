<?php
/**
 * Three-card summary strip: the shared metric-card (prominent size), the
 * same card the Overview and Assessments tabs use.
 */
$finalGrade = $summary['overall_percentage'] ?? null;

$remarksMeta = match ($summary['remarks'] ?? null) {
    'Passed'     => ['label' => 'Passed',      'tone' => 'success', 'icon' => 'check'],
    'Failed'     => ['label' => 'Failed',      'tone' => 'danger',  'icon' => 'attention'],
    'Withdrawn'  => ['label' => 'Withdrawn',   'tone' => 'neutral', 'icon' => 'attention'],
    default      => ['label' => 'In Progress', 'tone' => 'warning', 'icon' => 'in-progress'],
};

$gradedCount = (int) ($summary['graded_count'] ?? 0);
$totalCount = (int) ($summary['total_count'] ?? 0);

$isPublished = !empty($summary['is_published']);
$gradeLabel = $summary['grade_label'] ?? null;
?>

<?php
$overallValue = $finalGrade !== null ? number_format((float) $finalGrade, 2) . '%' : '—';

$overallHtml = esc($overallValue);

if ($gradeLabel !== null) {
    $overallHtml .= ' <span class="ml-1 rounded-full bg-[#0040A6]/10 px-2 py-0.5 text-sm" data-grades-scale-grade>' . esc($gradeLabel) . '</span>';
}
?>

<div class="grid grid-cols-1 gap-4 sm:grid-cols-3">

    <?= view('components/metric-card', [
        'label' => 'Overall Grade',
        'icon' => 'points',
        'size' => 'prominent',
        'value_html' => $overallHtml,
        'detail' => match (true) {
            !$isPublished => "Your instructor hasn't published the grading yet",
            !empty($summary['is_override']) => 'Set by your instructor',
            $finalGrade !== null => 'From your graded work so far',
            default => 'Nothing graded yet',
        },
    ], ['saveData' => false]) ?>

    <?= view('components/metric-card', [
        'label' => 'Points Earned',
        'icon' => 'score',
        'size' => 'prominent',
        'value' => round((float) ($summary['points_earned'] ?? 0), 2) . '/' . round((float) ($summary['points_possible'] ?? 0), 2),
        'detail' => $gradedCount . ' of ' . $totalCount . ' graded',
        'detail_attrs' => 'data-grades-graded-count',
    ], ['saveData' => false]) ?>

    <?= view('components/metric-card', [
        'label' => 'Standing',
        'icon' => $remarksMeta['icon'],
        'size' => 'prominent',
        'tone' => $remarksMeta['tone'],
        'value' => $remarksMeta['label'],
        'detail' => '',
    ], ['saveData' => false]) ?>

</div>
