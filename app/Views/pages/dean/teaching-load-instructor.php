<?php
/**
 * One instructor's teaching load in a term: their totals and each section they teach in the department,
 * every section opening read-only. View only.
 *
 * @var array<string, mixed> $instructor
 * @var list<array<string, mixed>> $sections
 * @var list<array<string, mixed>> $terms
 * @var int $highLoadUnits
 */
$base = site_url('dean/teaching-load');

$units = static fn (float $value): string => rtrim(rtrim(number_format($value, 1), '0'), '.');

$duration = static function (?float $hours): string {
    if ($hours === null) {
        return '—';
    }

    return $hours < 24 ? rtrim(rtrim(number_format($hours, 1), '0'), '.') . ' h' : rtrim(rtrim(number_format($hours / 24, 1), '0'), '.') . ' days';
};

$termOptions = [];

foreach ($terms as $t) {
    $termOptions[(string) $t['id']] = $t['name'] . ' (' . $t['academic_year'] . ')';
}

$rows = [];

foreach ($sections as $s) {
    $rows[] = [
        'id'    => (int) $s['offering_id'],
        'cells' => [
            'course'     => ['html' => '<a class="font-semibold text-brand hover:underline" href="' . esc(site_url('dean/courses/' . (int) $s['offering_id'] . '/' . $s['slug'])) . '">' . esc(trim($s['course_code'] . ' ' . $s['section_code'])) . '</a><span class="block text-xs text-[#646464]">' . esc((string) $s['course_title']) . '</span>'],
            'students'   => (string) count($s['student_ids']),
            'units'      => $units((float) $s['units']),
            'ungraded'   => (string) $s['ungraded'],
            'turnaround' => $duration($s['turnaround']),
        ],
    ];
}

$columns = [
    ['key' => 'course', 'label' => 'Section'],
    ['key' => 'students', 'label' => 'Students'],
    ['key' => 'units', 'label' => 'Units'],
    ['key' => 'ungraded', 'label' => 'Waiting to be graded'],
    ['key' => 'turnaround', 'label' => 'Grading turnaround'],
];
?>

<?= $this->extend('layouts/app') ?>

<?= $this->section('content') ?>

<div class="flex flex-col gap-6 py-3">

    <?= view('components/page-head', [
        'title'       => (string) $instructor['name'],
        'icon'        => 'user',
        'description' => 'Their sections in your department this term. You can look, not change.',
        'backUrl'     => $base . ($term !== null ? '?term=' . (int) $term : ''),
        'backLabel'   => 'Back to teaching load',
    ], ['saveData' => false]) ?>

    <?php if (count($termOptions) > 1): ?>
        <?= view('components/toolbar', [
            'action'   => site_url('dean/teaching-load/' . (int) $instructor['id']),
            'filtered' => false,
            'fields'   => [['type' => 'select', 'name' => 'term', 'label' => 'Term', 'options' => $termOptions, 'value' => (string) ($term ?? '')]],
        ], ['saveData' => false]) ?>
    <?php endif; ?>

    <div class="grid grid-cols-2 gap-4 lg:grid-cols-4" data-section="totals">
        <?= view('components/metric-card', ['label' => 'Units', 'value' => $units((float) $instructor['units']), 'detail' => $instructor['high_load'] ? 'High load: ' . $highLoadUnits . ' or more' : 'High load starts at ' . $highLoadUnits, 'icon' => 'modules', 'tone' => $instructor['high_load'] ? 'warning' : 'neutral'], ['saveData' => false]) ?>
        <?= view('components/metric-card', ['label' => 'Sections', 'value' => (string) $instructor['sections'], 'detail' => number_format((int) $instructor['students']) . ' students', 'icon' => 'hat'], ['saveData' => false]) ?>
        <?= view('components/metric-card', ['label' => 'Waiting to be graded', 'value' => (string) $instructor['ungraded'], 'detail' => 'Handed in, not graded yet', 'icon' => 'tasks'], ['saveData' => false]) ?>
        <?= view('components/metric-card', ['label' => 'Grading turnaround', 'value' => $duration($instructor['turnaround']), 'detail' => 'From handed in to graded', 'icon' => 'clock'], ['saveData' => false]) ?>
    </div>

    <?= view('components/data-table', [
        'caption'    => 'Sections taught by ' . $instructor['name'] . ' in your department',
        'columns'    => $columns,
        'rows'       => $rows,
        'emptyTitle' => 'No sections this term',
        'emptyText'  => 'They teach in your department, but not in this term. Choose another term above.',
        'emptyIcon'  => 'hat',
    ], ['saveData' => false]) ?>

</div>

<?= $this->endSection() ?>
