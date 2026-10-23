<?php
/**
 * The dean's review of what chairpersons changed in the department's courses. Each row says who changed
 * what in which course and when, links into the course (read-only for the dean) and can be marked as
 * reviewed. Nothing here blocks or reverts a change.
 *
 * @var App\Services\Courses\ChairpersonReviewFilters $filters
 * @var list<array<string, mixed>> $entries
 * @var array{unreviewed: int, reviewed: int} $counts
 * @var list<array{id: int, name: string}> $chairpersons
 * @var list<array<string, mixed>> $subDepartments
 */
$base = site_url('dean/chairperson-review');

$options = static function (array $rows, string $any): array {
    $list = ['' => $any];

    foreach ($rows as $row) {
        $list[(string) $row['id']] = trim((string) $row['name']) ?: 'Unnamed';
    }

    return $list;
};

$tabs = [];

foreach ([['unreviewed', 'To review', $counts['unreviewed']], ['reviewed', 'Reviewed', $counts['reviewed']], ['all', 'All', $counts['unreviewed'] + $counts['reviewed']]] as [$view, $label, $count]) {
    $tabs[] = ['label' => $label, 'href' => $base . (($query = http_build_query($filters->toQuery(['view' => $view === 'unreviewed' ? null : $view]))) === '' ? '' : '?' . $query), 'active' => $filters->view === $view, 'count' => $count];
}

$back = http_build_query($filters->toQuery() + ($pageNumber > 1 ? ['page' => $pageNumber] : []));

$primary = 'rounded-full bg-brand px-4 py-1.5 text-xs font-semibold text-white transition hover:bg-brand-dark focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/55 focus-visible:ring-offset-2';

$rows = [];

foreach ($entries as $e) {
    $course = $e['course_code'] !== null
        ? ($e['slug'] !== null
            ? '<a class="font-semibold text-brand hover:underline" href="' . esc(site_url('dean/courses/' . (int) $e['offering_id'] . '/' . $e['slug'])) . '">' . esc(trim($e['course_code'] . ' ' . $e['section_code'])) . '</a>'
            : esc(trim($e['course_code'] . ' ' . $e['section_code'])))
        . ($e['sub_department'] ? '<span class="block text-xs text-[#646464]">' . esc((string) $e['sub_department']) . '</span>' : '')
        : '—';

    $rows[] = [
        'id'      => (int) $e['id'],
        'cells'   => [
            'who'    => $e['actor'] ?: '—',
            'what'   => $e['description'],
            'course' => ['html' => $course],
            'when'   => date('M j, Y g:i a', strtotime((string) $e['created_at'])),
        ],
        'actions' => $e['reviewed_at'] === null
            ? '<form method="post" action="' . esc(site_url('dean/chairperson-review/reviewed'), 'attr') . '">' . csrf_field()
                . '<input type="hidden" name="id" value="' . (int) $e['id'] . '"><input type="hidden" name="back" value="' . esc($back, 'attr') . '">'
                . '<button type="submit" class="' . esc($primary, 'attr') . '" data-mark-reviewed>Mark reviewed</button></form>'
            : view('components/status-badge', ['label' => 'Reviewed ' . date('M j', strtotime((string) $e['reviewed_at'])), 'tone' => 'success', 'icon' => 'check'], ['saveData' => false]),
    ];
}

$columns = [
    ['key' => 'who', 'label' => 'Chairperson'],
    ['key' => 'what', 'label' => 'What changed'],
    ['key' => 'course', 'label' => 'Course'],
    ['key' => 'when', 'label' => 'When'],
];

$unreviewedOnPage = array_filter($entries, static fn (array $e): bool => $e['reviewed_at'] === null);
?>

<?= $this->extend('layouts/app') ?>

<?= $this->section('content') ?>

<div class="py-3">

<?php // The way back stays on the background, above the panel, not in the box. ?>
<?= view('components/back-link', ['backUrl' => site_url('dean/dashboard'), 'backLabel' => 'Back to dashboard'], ['saveData' => false]) ?>

<div class="flex flex-col gap-6 rounded-3xl bg-white p-5 shadow-sm sm:p-8" data-page-panel>

    <?= view('components/page-head', [
        'title'       => 'Chairperson review',
        'icon'        => 'tasks',
        'description' => 'What chairpersons changed in courses they do not teach, in your department. You can look and mark what you have reviewed; nothing here blocks or reverts a change.',
        'actions'     => $unreviewedOnPage === [] ? '' : '<form method="post" action="' . esc(site_url('dean/chairperson-review/reviewed'), 'attr') . '">' . csrf_field()
            . implode('', array_map(static fn (array $e): string => '<input type="hidden" name="ids[]" value="' . (int) $e['id'] . '">', $unreviewedOnPage))
            . '<input type="hidden" name="back" value="' . esc($back, 'attr') . '"><button type="submit" class="' . esc($primary, 'attr') . '" data-mark-page>Mark these ' . count($unreviewedOnPage) . ' reviewed</button></form>',
    ], ['saveData' => false]) ?>

    <?= view('components/tabs', ['tabs' => $tabs, 'ariaLabel' => 'Review state'], ['saveData' => false]) ?>

    <?= view('components/toolbar', [
        'surface'  => 'tinted', // on the page's white panel a white card would not stand out
        'action'   => $base,
        'filtered' => $filters->isFiltered(),
        'clearUrl' => $filters->view === 'unreviewed' ? $base : $base . '?view=' . $filters->view,
        'hidden'   => $filters->view === 'unreviewed' ? [] : ['view' => $filters->view],
        'fields'   => [
            ['type' => 'select', 'name' => 'chairperson', 'label' => 'Chairperson', 'options' => $options($chairpersons, 'Any chairperson'), 'value' => (string) ($filters->chairpersonId ?? '')],
            ['type' => 'select', 'name' => 'sub_department', 'label' => 'Sub-department', 'options' => $options($subDepartments, 'Any sub-department'), 'value' => (string) ($filters->subDepartmentId ?? '')],
            ['type' => 'date', 'name' => 'from', 'label' => 'From', 'value' => $filters->from ?? ''],
            ['type' => 'date', 'name' => 'to', 'label' => 'To', 'value' => $filters->to ?? ''],
        ],
    ], ['saveData' => false]) ?>

    <?php if ($loadError): ?>

        <div class="flex flex-col items-center justify-center gap-3 rounded-2xl border border-[#C33939]/30 bg-white px-8 py-14 text-center" role="alert" data-load-error>
            <?= icon('warning', 'h-10 w-10 text-[#C33939]') ?>
            <span class="text-sm font-semibold text-[#1A1C1E]">We could not load the changes</span>
            <span class="text-xs text-[#646464]">Something went wrong on our side. Nothing has been marked.</span>
            <a href="<?= esc($base) ?>" class="<?= esc($primary, 'attr') ?>">Try again</a>
        </div>

    <?php else: ?>

        <?= view('components/data-table', [
            'caption'    => 'Changes chairpersons made in the department\'s courses, newest first',
            'columns'    => $columns,
            'rows'       => $rows,
            'emptyTitle' => $filters->isFiltered() ? 'No changes match these filters' : ($filters->view === 'reviewed' ? 'Nothing reviewed yet' : ($filters->view === 'all' ? 'No changes yet' : 'Nothing to review')),
            'emptyText'  => $filters->isFiltered()
                ? 'Try a different chairperson, sub-department or dates.'
                : 'When a chairperson changes a course they do not teach, it appears here.',
            'emptyIcon'  => 'tasks',
        ], ['saveData' => false]) ?>

        <?= view('components/pagination', ['page' => $pageNumber, 'pages' => $pages, 'total' => $total, 'baseUrl' => $base, 'query' => $filters->toQuery()], ['saveData' => false]) ?>

    <?php endif; ?>

</div>

</div>

<?= $this->endSection() ?>
