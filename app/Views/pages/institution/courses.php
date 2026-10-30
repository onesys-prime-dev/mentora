<?php
/**
 * The institution's course catalog.
 *
 * @var list<array<string, mixed>> $courseRows
 * @var int                        $courseTotal
 * @var int                        $coursePages
 * @var int                        $coursePage
 * @var array<string, mixed>       $courseFilters
 * @var list<array<string, mixed>> $coursePrograms        every program, for the filter
 * @var list<array<string, mixed>> $courseActivePrograms  programs a course can be added to
 * @var bool                       $courseCanEdit
 * @var list<string>               $courseLockedFields    what the SIS decides
 */
$base    = 'institution/courses';
$primary = 'rounded-full bg-brand px-5 py-2 text-sm font-semibold text-white transition hover:bg-brand-dark focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/50';
$link    = 'text-sm font-semibold text-brand hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/50 rounded';
$warn    = 'text-sm font-semibold text-[#C33939] hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#C33939]/50 rounded';

$filtered = $courseFilters['q'] !== '' || $courseFilters['program'] > 0 || $courseFilters['status'] !== '';

$query = array_filter([
    'q'       => $courseFilters['q'],
    'program' => $courseFilters['program'] > 0 ? $courseFilters['program'] : null,
    'status'  => $courseFilters['status'],
    'sort'    => $courseFilters['sort'],
    'dir'     => $courseFilters['dir'],
], static fn ($v): bool => $v !== null && $v !== '');

$sort = $courseFilters['sort'] !== '' ? $courseFilters['sort'] : 'code';
$dir  = $courseFilters['dir'] === 'desc' ? 'desc' : 'asc';

$sortUrl = static function (string $column, string $next) use ($base, $query): string {
    return site_url($base) . '?' . http_build_query(array_merge($query, ['sort' => $column, 'dir' => $next, 'page' => null]));
};

$tones   = ['active' => 'success', 'inactive' => 'neutral', 'archived' => 'neutral'];
$fmtUnit = static fn ($u): string => rtrim(rtrim(number_format((float) $u, 1), '0'), '.');
$json    = static fn (array $values): string => esc(json_encode($values, JSON_UNESCAPED_UNICODE));

$rows = [];

foreach ($courseRows as $course) {
    $managed = (int) $course['sis_managed'] === 1;
    $active  = $course['status'] === 'active';

    $actions = '';

    if ($courseCanEdit) {
        $actions .= '<button type="button" class="' . $link . '" data-dialog-open="course-edit"'
            . ' data-dialog-title="Edit ' . esc($course['course_code']) . '" data-dialog-label="Save"'
            . ' data-dialog-action="' . esc(site_url($base . '/' . $course['id'])) . '"'
            . ' data-dialog-values="' . $json(['course_code' => $course['course_code'], 'course_title' => $course['course_title'], 'units' => $fmtUnit($course['units']), 'description' => (string) ($course['description'] ?? '')]) . '"'
            . ($managed ? ' data-dialog-locked="' . $json($courseLockedFields) . '"' : '') . '>Edit</button>';

        if ($active) {
            $actions .= ' <button type="button" class="' . $warn . ' ml-3" data-dialog-open="course-confirm"'
                . ' data-dialog-title="Deactivate ' . esc($course['course_code']) . '?"'
                . ' data-dialog-text="It stays in your records with its offerings and grades, but cannot get new offerings. It must have no offerings in an active term."'
                . ' data-dialog-label="Deactivate" data-dialog-action="' . esc(site_url($base . '/' . $course['id'] . '/deactivate')) . '">Deactivate</button>';
        } elseif ($course['program_status'] === 'active') {
            $actions .= ' <form class="ml-3 inline" method="post" action="' . esc(site_url($base . '/' . $course['id'] . '/reactivate')) . '">' . csrf_field()
                . '<button type="submit" class="' . $link . '">Reactivate</button></form>';
        }

        if ($course['status'] !== 'archived') {
            $actions .= ' <button type="button" class="' . $link . ' ml-3" data-dialog-open="course-confirm"'
                . ' data-dialog-title="Archive ' . esc($course['course_code']) . '?"'
                . ' data-dialog-text="Archiving is for courses that are no longer taught. They stay for reports and history, and you can reactivate one later."'
                . ' data-dialog-label="Archive" data-dialog-action="' . esc(site_url($base . '/' . $course['id'] . '/archive')) . '">Archive</button>';
        }
    }

    $rows[] = [
        'id'      => $course['id'],
        'label'   => $course['course_code'],
        'cells'   => [
            'code'    => ['html' => '<span class="font-mono text-xs font-semibold">' . esc($course['course_code']) . '</span>'
                . ($managed ? ' <span class="ml-1 inline-flex align-middle" title="Managed by your student information system">' . icon('lock', 'h-3.5 w-3.5 text-[#646464]') . '<span class="sr-only">Managed by the SIS</span></span>' : '')],
            'title'   => ['html' => '<span class="font-medium">' . esc($course['course_title']) . '</span>'],
            'program' => $course['program_code'] . ' · ' . $course['program_name'],
            'units'   => $fmtUnit($course['units']),
            'offerings' => (string) $course['offering_count'],
            'status'  => ['html' => view('components/status-badge', ['label' => ucfirst((string) $course['status']), 'tone' => $tones[$course['status']] ?? 'neutral'], ['saveData' => false])],
        ],
        'actions' => $actions,
    ];
}
?>

<?= $this->extend('layouts/app') ?>

<?= $this->section('content') ?>

<div class="py-3">

<?php // The way back stays on the background, above the panel, not in the box. ?>
<?= view('components/back-link', ['backUrl' => site_url('institution/structure'), 'backLabel' => 'Back to structure'], ['saveData' => false]) ?>

<div class="flex flex-col gap-6 rounded-3xl bg-white p-5 shadow-sm sm:p-8" data-page-panel>

    <?= view('components/page-head', [
        'title'       => 'Courses',
        'icon'        => 'hat',
        'description' => 'Every course your institution offers, filed under its program.',
        'actions'     => $courseCanEdit && $courseActivePrograms !== []
            ? '<button type="button" class="' . $primary . '" data-dialog-open="course-add" data-dialog-title="Add a course" data-dialog-action="' . esc(site_url($base)) . '" data-dialog-label="Add course">Add a course</button>'
            : '',
    ], ['saveData' => false]) ?>

    <?php if (! $courseCanEdit): ?>
        <?= view('components/lock-note', ['kind' => 'read_only'], ['saveData' => false]) ?>
    <?php elseif ($courseActivePrograms === []): ?>
        <?= view('components/alert', ['tone' => 'info', 'title' => 'Add a program first', 'text' => 'A course is filed under a program, and there is no active program yet. Add one on the Structure page.'], ['saveData' => false]) ?>
    <?php endif; ?>

    <?php if (session()->has('message')): ?>
        <?= view('components/alert', ['tone' => 'success', 'text' => (string) session('message')], ['saveData' => false]) ?>
    <?php endif; ?>
    <?php if (session()->has('error')): ?>
        <?= view('components/alert', ['tone' => 'danger', 'text' => (string) session('error')], ['saveData' => false]) ?>
    <?php endif; ?>

    <?php
    $programOptions = ['' => 'Any program'];

    foreach ($coursePrograms as $program) {
        $programOptions[(string) $program['id']] = $program['code'] . ' · ' . $program['name'];
    }
    ?>

    <?= view('components/toolbar', [
        'surface'  => 'tinted', // on the page's white panel a white card would not stand out
        'inline'   => true,
        'action'   => site_url($base),
        'filtered' => $filtered,
        'fields'   => [
            ['type' => 'search', 'name' => 'q', 'label' => 'Search', 'placeholder' => 'Course code or title', 'value' => $courseFilters['q'], 'span' => 'lg:col-span-2'],
            ['type' => 'select', 'name' => 'program', 'label' => 'Program', 'options' => $programOptions, 'value' => $courseFilters['program'] > 0 ? (string) $courseFilters['program'] : ''],
            ['type' => 'select', 'name' => 'status', 'label' => 'Status', 'options' => ['' => 'Any status', 'active' => 'Active', 'inactive' => 'Inactive', 'archived' => 'Archived'], 'value' => $courseFilters['status']],
        ],
    ], ['saveData' => false]) ?>

    <p class="text-sm text-[#646464]" aria-live="polite" data-course-total>
        <?= esc(number_format($courseTotal)) ?> <?= $courseTotal === 1 ? 'course' : 'courses' ?><?= $filtered ? ' match' : '' ?>
    </p>

    <?php // Only the table scrolls; the heading, the filters and the count stay put (lg and up). ?>
    <div class="flex flex-col gap-6" data-panel-scroll="inner">

    <?= view('components/data-table', [
        'fill'       => true, // the table scrolls under its own heading row
        'caption'    => 'Courses',
        'columns'    => [
            ['key' => 'code', 'label' => 'Code', 'sortable' => true],
            ['key' => 'title', 'label' => 'Title', 'sortable' => true],
            ['key' => 'program', 'label' => 'Program', 'sortable' => true],
            ['key' => 'units', 'label' => 'Units', 'sortable' => true],
            ['key' => 'offerings', 'label' => 'Offerings'],
            ['key' => 'status', 'label' => 'Status', 'sortable' => true],
        ],
        'rows'       => $rows,
        'sort'       => $sort,
        'dir'        => $dir,
        'sortUrl'    => $sortUrl,
        'emptyIcon'  => 'hat',
        'emptyTitle' => $filtered ? 'No courses match these filters' : 'No courses yet',
        'emptyText'  => $filtered ? 'Try a different search, program or status.' : ($courseCanEdit ? 'Add the first course to start your catalog.' : 'Courses will be listed here.'),
    ], ['saveData' => false]) ?>

    </div>

    <?= view('components/pagination', ['page' => $coursePage, 'pages' => $coursePages, 'total' => $courseTotal, 'baseUrl' => site_url($base), 'query' => $query], ['saveData' => false]) ?>

</div>

</div>

<?php if ($courseCanEdit): ?>
    <?php
    $field = static fn (array $f): string => view('components/form-field', $f, ['saveData' => false]);

    $buttons = '<div class="flex flex-wrap items-center justify-end gap-3">'
        . '<button type="button" data-dialog-close class="rounded-full border border-gray-300 px-5 py-2 text-sm font-semibold text-[#3D3D3D] transition hover:bg-gray-50">Cancel</button>'
        . '<button type="submit" data-dialog-label class="rounded-full bg-brand px-5 py-2 text-sm font-semibold text-white transition hover:bg-brand-dark">Save</button></div>';

    // Built per dialog: each needs its own field ids.
    $common = static fn (): string => $field(['name' => 'course_code', 'label' => 'Code', 'required' => true, 'maxlength' => 50, 'attrs' => 'autocomplete="off"', 'help' => 'For example IT101. Shown in capitals.'])
        . $field(['name' => 'course_title', 'label' => 'Title', 'required' => true, 'maxlength' => 255, 'attrs' => 'autocomplete="off"'])
        . $field(['name' => 'units', 'label' => 'Units', 'required' => true, 'type' => 'number', 'attrs' => 'step="0.5" min="0.5" max="99.9" inputmode="decimal"'])
        . $field(['name' => 'description', 'label' => 'Description', 'type' => 'textarea', 'maxlength' => 5000, 'help' => 'Optional.']);

    $programSelect = ['' => 'Choose a program'];

    foreach ($courseActivePrograms as $program) {
        $programSelect[(string) $program['id']] = $program['code'] . ' · ' . $program['name'];
    }

    $lockNote = '<p class="flex items-start gap-2 rounded-xl bg-gray-50 px-3 py-2 text-xs text-[#646464]" data-dialog-lock hidden>'
        . icon('lock', 'mt-0.5 h-3.5 w-3.5 text-[#646464]')
        . '<span>The code, title and units are managed by your student information system. Change them there; the next sync brings them here.</span></p>';

    $addBody = '<form method="post" action="" data-dialog-form class="flex flex-col gap-4">' . csrf_field()
        . $field(['name' => 'program_id', 'label' => 'Program', 'type' => 'select', 'options' => $programSelect, 'required' => true])
        . $common()
        . '<p class="text-xs font-medium text-[#C33939]" data-form-error hidden></p>' . $buttons . '</form>';

    $editBody = '<form method="post" action="" data-dialog-form class="flex flex-col gap-4">' . csrf_field()
        . $lockNote . $common()
        . '<p class="text-xs font-medium text-[#C33939]" data-form-error hidden></p>' . $buttons . '</form>';
    ?>

    <?= view('components/dialog', ['id' => 'course-add', 'title' => 'Add a course', 'body' => $addBody], ['saveData' => false]) ?>
    <?= view('components/dialog', ['id' => 'course-edit', 'title' => 'Edit course', 'body' => $editBody], ['saveData' => false]) ?>
    <?= view('components/confirm', [
        'id'           => 'course-confirm',
        'title'        => 'Are you sure?',
        'text'         => 'This changes the catalog.',
        'action'       => site_url($base),
        'confirmLabel' => 'Confirm',
        'tone'         => 'danger',
    ], ['saveData' => false]) ?>
<?php endif; ?>

<?= $this->endSection() ?>
