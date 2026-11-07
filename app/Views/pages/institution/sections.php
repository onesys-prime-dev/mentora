<?php
/**
 * The institution's block sections (BSIT 1-A): a cohort of students that takes a set of courses together.
 *
 * @var list<array<string, mixed>> $sectionRows
 * @var int                        $sectionTotal
 * @var int                        $sectionPages
 * @var int                        $sectionPage
 * @var array<string, mixed>       $sectionFilters
 * @var array<string, mixed>       $sectionOptions   terms (all, and the open ones) and programs
 * @var bool                       $sectionCanEdit
 */
$base    = 'institution/sections';
$primary = 'rounded-full bg-brand px-5 py-2 text-sm font-semibold text-white transition hover:bg-brand-dark focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/50';
$link    = 'text-sm font-semibold text-brand hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/50 rounded';

$filtered = $sectionFilters['q'] !== '' || $sectionFilters['term'] > 0 || $sectionFilters['program'] > 0 || $sectionFilters['status'] !== '';

$query = array_filter([
    'q'       => $sectionFilters['q'],
    'term'    => $sectionFilters['term'] > 0 ? $sectionFilters['term'] : null,
    'program' => $sectionFilters['program'] > 0 ? $sectionFilters['program'] : null,
    'status'  => $sectionFilters['status'],
], static fn ($v): bool => $v !== null && $v !== '');

$tones = ['active' => 'success', 'archived' => 'neutral'];

$rows = [];

foreach ($sectionRows as $s) {
    $rows[] = [
        'id'      => $s['id'],
        'label'   => $s['code'],
        'cells'   => [
            'code'     => ['html' => '<a class="' . $link . '" href="' . esc(site_url($base . '/' . $s['id'])) . '">' . esc($s['code']) . '</a>'],
            'program'  => ['html' => '<span class="font-mono text-xs font-semibold">' . esc($s['program_code']) . '</span> <span class="text-[#646464]">' . esc($s['program_name']) . '</span>'],
            'year'     => 'Year ' . $s['year_level'],
            'term'     => $s['term_name'] . ' ' . $s['academic_year'],
            'courses'  => (string) $s['course_count'],
            'students' => ['html' => esc((string) $s['student_count']) . ' / ' . esc((string) $s['capacity'])
                . '<div class="mt-1.5 h-1.5 w-28 overflow-hidden rounded-full bg-gray-200" aria-hidden="true"><div class="h-full rounded-full ' . ((int) $s['capacity'] > 0 && (int) $s['student_count'] >= (int) $s['capacity'] ? 'bg-[#946200]' : 'bg-brand') . '" style="width: ' . ((int) $s['capacity'] > 0 ? min(100, (int) round((int) $s['student_count'] / (int) $s['capacity'] * 100)) : 0) . '%"></div></div>'],
            'status'   => ['html' => view('components/status-badge', ['label' => ucfirst((string) $s['status']), 'tone' => $tones[$s['status']] ?? 'neutral'], ['saveData' => false])],
        ],
        'actions' => '<a class="' . $link . '" href="' . esc(site_url($base . '/' . $s['id'])) . '" aria-label="Open ' . esc($s['code']) . '">Open</a>',
    ];
}

$termOptions = ['' => 'Any term'];

foreach ($sectionOptions['terms'] as $t) {
    $termOptions[(string) $t['id']] = $t['name'] . ' ' . $t['academic_year'];
}

$programOptions = ['' => 'Any program'];

foreach ($sectionOptions['programs'] as $p) {
    $programOptions[(string) $p['id']] = $p['code'] . ' · ' . $p['name'];
}

$canAdd = $sectionCanEdit && $sectionOptions['openTerms'] !== [] && $sectionOptions['programs'] !== [];
?>

<?= $this->extend('layouts/app') ?>

<?= $this->section('content') ?>

<div class="py-3">

<?php // The way back stays on the background, above the panel, not in the box. ?>
<?= view('components/back-link', ['backUrl' => site_url('institution/dashboard'), 'backLabel' => 'Back to home'], ['saveData' => false]) ?>

<div class="flex flex-col gap-6 rounded-3xl bg-white p-5 shadow-sm sm:p-8" data-page-panel>

    <?= view('components/page-head', [
        'title'       => 'Sections',
        'icon'        => 'user',
        'description' => 'A group of students, such as BSIT 1-A, that takes a set of courses together in a term.',
        'actions'     => $canAdd
            ? '<button type="button" class="' . $primary . '" data-dialog-open="section-add" data-dialog-title="Add a section" data-dialog-action="' . esc(site_url($base)) . '" data-dialog-label="Add section">Add a section</button>'
            : '',
    ], ['saveData' => false]) ?>

    <?php if (! $sectionCanEdit): ?>
        <?= view('components/lock-note', ['kind' => 'read_only'], ['saveData' => false]) ?>
    <?php elseif ($sectionOptions['programs'] === []): ?>
        <?= view('components/alert', ['tone' => 'info', 'title' => 'Add a program first', 'text' => 'A section belongs to a program, and there is none yet. Add one on the Structure tab.'], ['saveData' => false]) ?>
    <?php elseif ($sectionOptions['openTerms'] === []): ?>
        <?= view('components/alert', ['tone' => 'info', 'title' => 'Add a term first', 'text' => 'Sections are made in an upcoming or active term, and there is none yet. Add one on the Terms tab.'], ['saveData' => false]) ?>
    <?php endif; ?>

    <?php if (session()->has('message')): ?>
        <?= view('components/alert', ['tone' => 'success', 'text' => (string) session('message')], ['saveData' => false]) ?>
    <?php endif; ?>
    <?php if (session()->has('error')): ?>
        <?= view('components/alert', ['tone' => 'danger', 'text' => (string) session('error')], ['saveData' => false]) ?>
    <?php endif; ?>

    <?= view('components/toolbar', [
        'surface'  => 'tinted', // on the page's white panel a white card would not stand out
        'inline'   => true,
        'action'   => site_url($base),
        'filtered' => $filtered,
        'fields'   => [
            ['type' => 'search', 'name' => 'q', 'label' => 'Search', 'placeholder' => 'Section or program', 'value' => $sectionFilters['q'], 'span' => 'lg:col-span-2'],
            ['type' => 'select', 'name' => 'term', 'label' => 'Term', 'options' => $termOptions, 'value' => $sectionFilters['term'] > 0 ? (string) $sectionFilters['term'] : ''],
            ['type' => 'select', 'name' => 'program', 'label' => 'Program', 'options' => $programOptions, 'value' => $sectionFilters['program'] > 0 ? (string) $sectionFilters['program'] : ''],
            ['type' => 'select', 'name' => 'status', 'label' => 'Status', 'options' => ['' => 'Any status', 'active' => 'Active', 'archived' => 'Archived'], 'value' => $sectionFilters['status']],
        ],
    ], ['saveData' => false]) ?>

    <p class="text-sm text-[#646464]" aria-live="polite" data-section-total>
        <?= esc(number_format($sectionTotal)) ?> <?= $sectionTotal === 1 ? 'section' : 'sections' ?><?= $filtered ? ' match' : '' ?>
    </p>

    <?php // Only the table scrolls; the heading, the filters and the count stay put (lg and up). ?>
    <div class="flex flex-col gap-6" data-panel-scroll="inner">

    <?= view('components/data-table', [
        'fill'       => true,
        'caption'    => 'Sections',
        'columns'    => [
            ['key' => 'code', 'label' => 'Section'],
            ['key' => 'program', 'label' => 'Program'],
            ['key' => 'year', 'label' => 'Year'],
            ['key' => 'term', 'label' => 'Term'],
            ['key' => 'courses', 'label' => 'Courses'],
            ['key' => 'students', 'label' => 'Students / capacity'],
            ['key' => 'status', 'label' => 'Status'],
        ],
        'rows'       => $rows,
        'emptyIcon'  => 'user',
        'emptyTitle' => $filtered ? 'No sections match these filters' : 'No sections yet',
        'emptyText'  => $filtered ? 'Try a different search, term or program.' : ($canAdd ? 'Add the first section, then give it its courses and students.' : 'Sections will be listed here.'),
    ], ['saveData' => false]) ?>

    </div>

    <?= view('components/pagination', ['page' => $sectionPage, 'pages' => $sectionPages, 'total' => $sectionTotal, 'baseUrl' => site_url($base), 'query' => $query], ['saveData' => false]) ?>

</div>

</div>

<?php if ($canAdd): ?>
    <?php
    $field = static fn (array $f): string => view('components/form-field', $f, ['saveData' => false]);

    $buttons = static fn (string $label): string => '<div class="flex flex-wrap items-center justify-end gap-3">'
        . '<button type="button" data-dialog-close class="rounded-full border border-gray-300 px-5 py-2 text-sm font-semibold text-[#3D3D3D] transition hover:bg-gray-50">Cancel</button>'
        . '<button type="submit" data-dialog-label class="rounded-full bg-brand px-5 py-2 text-sm font-semibold text-white transition hover:bg-brand-dark">' . esc($label) . '</button></div>';

    $form = static fn (string $inside, string $label): string => '<form method="post" action="" data-dialog-form class="flex flex-col gap-4">' . csrf_field()
        . $inside . '<p class="text-xs font-medium text-[#C33939]" data-form-error hidden></p>' . $buttons($label) . '</form>';

    $termSelect = ['' => 'Choose a term'];

    foreach ($sectionOptions['openTerms'] as $t) {
        $termSelect[(string) $t['id']] = $t['name'] . ' ' . $t['academic_year'] . ($t['status'] === 'active' ? ' (active)' : '');
    }

    $programSelect = ['' => 'Choose a program'];

    foreach ($sectionOptions['programs'] as $p) {
        $programSelect[(string) $p['id']] = $p['code'] . ' · ' . $p['name'];
    }
    ?>

    <?= view('components/dialog', ['id' => 'section-add', 'title' => 'Add a section', 'body' => $form(
        $field(['name' => 'term_id', 'label' => 'Term', 'type' => 'select', 'options' => $termSelect, 'required' => true])
        . $field(['name' => 'program_id', 'label' => 'Program', 'type' => 'select', 'options' => $programSelect, 'required' => true])
        . $field(['name' => 'year_level', 'label' => 'Year level', 'required' => true, 'type' => 'number', 'value' => '1', 'attrs' => 'min="1" max="12" inputmode="numeric"', 'help' => 'For example 1 for first year.'])
        . $field(['name' => 'code', 'label' => 'Section name', 'required' => true, 'maxlength' => 50, 'attrs' => 'autocomplete="off"', 'help' => 'For example BSIT 1-A. It must be different from every other section in the term, and shows in capitals.'])
        . $field(['name' => 'capacity', 'label' => 'Capacity', 'required' => true, 'type' => 'number', 'value' => '40', 'attrs' => 'min="1" max="1000" inputmode="numeric"', 'help' => 'How many students the section takes.']),
        'Add section',
    )], ['saveData' => false]) ?>
<?php endif; ?>

<?= $this->endSection() ?>
