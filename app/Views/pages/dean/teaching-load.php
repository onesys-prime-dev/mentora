<?php
/**
 * The dean's teaching load: each instructor of the department with what they carry in a term. View only.
 * An instructor carrying the institution's high-load number of units or more is marked. The name opens
 * the instructor's own page, which lists their sections.
 *
 * @var App\Services\Courses\TeachingLoadFilters $filters
 * @var list<array<string, mixed>> $instructors
 * @var list<array<string, mixed>> $terms
 * @var list<array<string, mixed>> $subDepartments
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

$subOptions = ['' => 'Any sub-department'];

foreach ($subDepartments as $sd) {
    $subOptions[(string) $sd['id']] = (string) $sd['name'];
}

$carry = $filters->toQuery(['sort' => null, 'dir' => null]);

$rows = [];

foreach ($instructors as $i) {
    $rows[] = [
        'id'    => (int) $i['id'],
        'cells' => [
            'name'       => ['html' => '<a class="font-semibold text-brand hover:underline" href="' . esc($base . '/' . (int) $i['id'] . (($q = http_build_query(array_filter(['term' => $term, 'sub_department' => null]))) === '' ? '' : '?' . $q)) . '">' . esc($i['name']) . '</a>'
                . ($i['high_load'] ? '<span class="mt-1 block">' . view('components/status-badge', ['label' => 'High load', 'tone' => 'warning', 'icon' => 'attention'], ['saveData' => false]) . '</span>' : '')],
            'sections'   => (string) $i['sections'],
            'students'   => number_format((int) $i['students']),
            'units'      => $units((float) $i['units']),
            'ungraded'   => (string) $i['ungraded'],
            'turnaround' => $duration($i['turnaround']),
        ],
    ];
}

$columns = [
    ['key' => 'name', 'label' => 'Instructor', 'sortable' => true],
    ['key' => 'sections', 'label' => 'Sections', 'sortable' => true],
    ['key' => 'students', 'label' => 'Students', 'sortable' => true],
    ['key' => 'units', 'label' => 'Units', 'sortable' => true],
    ['key' => 'ungraded', 'label' => 'Waiting to be graded', 'sortable' => true],
    ['key' => 'turnaround', 'label' => 'Grading turnaround', 'sortable' => true],
];

$sortUrl = static fn (string $column, string $next): string => $base . '?' . http_build_query($carry + ['sort' => $column, 'dir' => $next]);
?>

<?= $this->extend('layouts/app') ?>

<?= $this->section('content') ?>

<div class="py-3">

<?php // The way back stays on the background, above the panel, not in the box. ?>
<?= view('components/back-link', ['backUrl' => site_url('dean/dashboard'), 'backLabel' => 'Back to dashboard'], ['saveData' => false]) ?>

<div class="flex flex-col gap-6 rounded-3xl bg-white p-5 shadow-sm sm:p-8" data-page-panel>

    <?= view('components/page-head', [
        'title'       => 'Teaching load',
        'icon'        => 'modules',
        'description' => 'What each instructor in your department teaches in a term. You can look, not change. A high load is ' . $highLoadUnits . ' units or more; your institution sets that number.',
        'actions'     => $loadError ? '' : '<a data-export="csv" href="' . esc(site_url('dean/reports/export/teaching-load') . '?' . http_build_query($filters->toQuery(['term' => $term])), 'attr') . '" class="rounded-full bg-brand px-5 py-2 text-sm font-semibold text-white transition hover:bg-brand-dark focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/55 focus-visible:ring-offset-2">Download CSV</a>',
    ], ['saveData' => false]) ?>

    <?= view('components/toolbar', [
        'action'   => $base,
        'filtered' => $filters->subDepartmentId !== null,
        'clearUrl' => $base . ($term !== null ? '?term=' . (int) $term : ''),
        'hidden'   => $filters->toQuery(['term' => null, 'sub_department' => null]),
        'fields'   => [
            ['type' => 'select', 'name' => 'term', 'label' => 'Term', 'options' => $termOptions === [] ? ['' => 'No terms yet'] : $termOptions, 'value' => (string) ($term ?? '')],
            ['type' => 'select', 'name' => 'sub_department', 'label' => 'Sub-department', 'options' => $subOptions, 'value' => (string) ($filters->subDepartmentId ?? '')],
        ],
    ], ['saveData' => false]) ?>

    <?php if ($loadError): ?>

        <div class="flex flex-col items-center justify-center gap-3 rounded-2xl border border-[#C33939]/30 bg-white px-8 py-14 text-center" role="alert" data-load-error>
            <?= icon('warning', 'h-10 w-10 text-[#C33939]') ?>
            <span class="text-sm font-semibold text-[#1A1C1E]">We could not load the teaching load</span>
            <span class="text-xs text-[#646464]">Something went wrong on our side. Nothing has been lost.</span>
            <a href="<?= esc($base) ?>" class="rounded-full bg-brand px-5 py-2 text-sm font-semibold text-white transition hover:bg-brand-dark focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/55 focus-visible:ring-offset-2">Try again</a>
        </div>

    <?php else: ?>

        <?= view('components/data-table', [
            'caption'    => 'Teaching load per instructor',
            'columns'    => $columns,
            'rows'       => $rows,
            'sort'       => $filters->sort,
            'dir'        => $filters->dir,
            'sortUrl'    => $sortUrl,
            'emptyTitle' => $term === null ? 'No terms yet' : ($filters->subDepartmentId !== null ? 'No instructors in this sub-department' : 'No instructors teach this term'),
            'emptyText'  => $term === null ? 'When your department has courses running in a term, the instructors appear here.' : 'Instructors appear here once they are assigned to a section of your department.',
            'emptyIcon'  => 'modules',
        ], ['saveData' => false]) ?>

    <?php endif; ?>

</div>

</div>

<?= $this->endSection() ?>
