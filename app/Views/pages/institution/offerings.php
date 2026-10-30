<?php
/**
 * The institution's course offerings.
 *
 * @var list<array<string, mixed>> $offeringRows
 * @var int                        $offeringTotal
 * @var int                        $offeringPages
 * @var int                        $offeringPage
 * @var array<string, mixed>       $offeringFilters
 * @var array<string, mixed>       $offeringChoices      terms and programs for the filters
 * @var array<string, mixed>       $offeringOptions      courses and terms the add form can use
 * @var list<array<string, mixed>> $offeringInstructors  people who can be assigned
 * @var array<int, true>             $offeringSisInstructors instructor assignments the SIS supplies (not editable here)
 * @var array<int, string>         $offeringSchedules    each section's class times in a few words
 * @var bool                       $offeringCanEdit
 */
$base    = 'institution/offerings';
$primary = 'rounded-full bg-brand px-5 py-2 text-sm font-semibold text-white transition hover:bg-brand-dark focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/50';
$link    = 'text-sm font-semibold text-brand hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/50 rounded';
$warn    = 'text-sm font-semibold text-[#C33939] hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#C33939]/50 rounded';

$filtered = $offeringFilters['q'] !== '' || $offeringFilters['term'] > 0 || $offeringFilters['program'] > 0 || $offeringFilters['instructor'] !== '' || $offeringFilters['status'] !== '';

$query = array_filter([
    'q'          => $offeringFilters['q'],
    'term'       => $offeringFilters['term'] > 0 ? $offeringFilters['term'] : null,
    'program'    => $offeringFilters['program'] > 0 ? $offeringFilters['program'] : null,
    'instructor' => $offeringFilters['instructor'],
    'status'     => $offeringFilters['status'],
    'sort'       => $offeringFilters['sort'],
    'dir'        => $offeringFilters['dir'],
], static fn ($v): bool => $v !== null && $v !== '');

$sort = $offeringFilters['sort'] !== '' ? $offeringFilters['sort'] : 'course';
$dir  = $offeringFilters['dir'] === 'desc' ? 'desc' : 'asc';

$sortUrl = static fn (string $column, string $next): string => site_url($base) . '?' . http_build_query(array_merge($query, ['sort' => $column, 'dir' => $next, 'page' => null]));

$tones = ['active' => 'success', 'completed' => 'neutral', 'archived' => 'neutral'];
$json  = static fn (array $values): string => esc(json_encode($values, JSON_UNESCAPED_UNICODE));

$rows = [];

foreach ($offeringRows as $o) {
    $open = $o['status'] === 'active';

    $chips = '';

    foreach ($o['instructors'] as $i) {
        $chips .= '<span class="inline-flex items-center gap-2 rounded-full bg-brand-tint px-3 py-1 text-xs" data-instructor="' . esc((string) $i['assignment_id']) . '">' . esc($i['name']);

        if (isset($offeringSisInstructors[(int) $i['assignment_id']])) {
            $chips .= ' <span class="inline-flex items-center gap-1 text-[#646464]" data-sis-managed title="Managed by your student information system. Change it there; the next sync brings it here.">' . icon('lock', 'h-3 w-3') . 'From the SIS</span>';
        } elseif ($offeringCanEdit && $open) {
            $chips .= ' <button type="button" class="rounded font-semibold text-[#C33939] hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#C33939]/50"'
                . ' aria-label="Remove ' . esc($i['name']) . ' from ' . esc($o['course_code'] . ' ' . $o['section_code']) . '"'
                . ' data-dialog-open="offering-confirm"'
                . ' data-dialog-title="Remove ' . esc($i['name']) . '?"'
                . ' data-dialog-text="They stop teaching ' . esc($o['course_code'] . ' ' . $o['section_code']) . '. The work and grades they did stay, and so does the record of it."'
                . ' data-dialog-label="Remove" data-dialog-action="' . esc(site_url($base . '/instructors/' . $i['assignment_id'] . '/remove')) . '">Remove</button>';
        }

        $chips .= '</span> ';
    }

    if ($o['instructors'] === []) {
        $chips = '<span class="text-xs font-semibold text-[#946200]" data-no-instructor>No instructor</span> ';
    }

    $actions = '';

    if ($offeringCanEdit) {
        if ($open) {
            $actions .= '<button type="button" class="' . $link . '" data-dialog-open="offering-instructor"'
                . ' data-dialog-title="Assign an instructor to ' . esc($o['course_code'] . ' ' . $o['section_code']) . '" data-dialog-label="Assign"'
                . ' data-dialog-action="' . esc(site_url($base . '/' . $o['id'] . '/instructors')) . '">Assign instructor</button>'
                . ' <button type="button" class="' . $link . ' ml-3" data-dialog-open="offering-edit"'
                . ' data-dialog-title="Edit ' . esc($o['course_code'] . ' ' . $o['section_code']) . '" data-dialog-label="Save"'
                . ' data-dialog-action="' . esc(site_url($base . '/' . $o['id'])) . '"'
                . ' data-dialog-values="' . $json(['section_code' => $o['section_code'], 'capacity' => (string) $o['capacity'], 'description' => (string) ($o['description'] ?? '')]) . '">Edit</button>'
                . ' <button type="button" class="' . $warn . ' ml-3" data-dialog-open="offering-confirm"'
                . ' data-dialog-title="Archive ' . esc($o['course_code'] . ' ' . $o['section_code']) . '?"'
                . ' data-dialog-text="Archiving takes it out of use. Its content, work and grades are kept, and you can bring it back while its term is open. It cannot have students enrolled."'
                . ' data-dialog-label="Archive" data-dialog-action="' . esc(site_url($base . '/' . $o['id'] . '/archive')) . '">Archive</button>';
        } elseif ($o['status'] === 'archived') {
            $actions .= '<form class="inline" method="post" action="' . esc(site_url($base . '/' . $o['id'] . '/reactivate')) . '">' . csrf_field()
                . '<button type="submit" class="' . $link . '">Reactivate</button></form>';
        }
    }

    // Everyone who can see the list can look at the course itself (read only), and at its class times.
    $actions = '<a class="' . $link . ' mr-3" href="' . esc(site_url('institution/courses/' . $o['id'] . '/' . url_title((string) $o['course_title'], '-', true) . '/overview')) . '" data-view-course>View course</a>'
        . '<a class="' . $link . ' mr-3" href="' . esc(site_url($base . '/' . $o['id'] . '/schedule')) . '" aria-label="Class times of ' . esc($o['course_code'] . ' ' . $o['section_code']) . '" data-class-times>Class times</a>' . $actions;

    $meets = $offeringSchedules[(int) $o['id']] ?? null;

    $rows[] = [
        'id'      => $o['id'],
        'label'   => $o['course_code'] . ' ' . $o['section_code'],
        'cells'   => [
            'course'      => ['html' => '<span class="font-mono text-xs font-semibold">' . esc($o['course_code']) . '</span> <span class="font-medium">' . esc($o['course_title']) . '</span>'],
            'section'     => (string) $o['section_code'],
            'term'        => $o['term_name'] . ' ' . $o['academic_year'],
            'meets'       => ['html' => $meets === null ? '<span class="text-[#646464]">—</span>' : '<span class="text-xs text-[#3D3D3D]" data-meets>' . esc($meets) . '</span>'],
            'instructors' => ['html' => $chips],
            'capacity'    => ['html' => '<a class="' . $link . '" href="' . esc(site_url('institution/enrolments/offering/' . $o['id'])) . '" aria-label="Roster of ' . esc($o['course_code'] . ' ' . $o['section_code']) . '">' . esc((string) $o['enrolled_count']) . ' / ' . esc((string) $o['capacity']) . '</a>'
                . '<div class="mt-1.5 h-1.5 w-28 overflow-hidden rounded-full bg-gray-200" aria-hidden="true"><div class="h-full rounded-full ' . ((int) $o['capacity'] > 0 && (int) $o['enrolled_count'] >= (int) $o['capacity'] ? 'bg-[#946200]' : 'bg-brand') . '" style="width: ' . ((int) $o['capacity'] > 0 ? min(100, (int) round((int) $o['enrolled_count'] / (int) $o['capacity'] * 100)) : 0) . '%"></div></div>'],
            'status'      => ['html' => view('components/status-badge', ['label' => ucfirst((string) $o['status']), 'tone' => $tones[$o['status']] ?? 'neutral'], ['saveData' => false])],
        ],
        'actions' => $actions,
    ];
}

$termOptions = ['' => 'Any term'];

foreach ($offeringChoices['terms'] as $t) {
    $termOptions[(string) $t['id']] = $t['name'] . ' ' . $t['academic_year'];
}

$programOptions = ['' => 'Any program'];

foreach ($offeringChoices['programs'] as $p) {
    $programOptions[(string) $p['id']] = $p['code'] . ' · ' . $p['name'];
}

$instructorOptions = ['' => 'Any instructor', 'none' => 'No instructor yet'];

foreach ($offeringInstructors as $i) {
    $instructorOptions[(string) $i['id']] = $i['name'];
}

$canAdd = $offeringCanEdit && $offeringOptions['courses'] !== [] && $offeringOptions['terms'] !== [];
?>

<?= $this->extend('layouts/app') ?>

<?= $this->section('content') ?>

<div class="py-3">

<?php // The way back stays on the background, above the panel, not in the box. ?>
<?= view('components/back-link', ['backUrl' => site_url('institution/dashboard'), 'backLabel' => 'Back to home'], ['saveData' => false]) ?>

<div class="flex flex-col gap-6 rounded-3xl bg-white p-5 shadow-sm sm:p-8" data-page-panel>

    <?= view('components/page-head', [
        'title'       => 'Offerings',
        'icon'        => 'modules',
        'description' => 'A course taught in a term, in a section, and who teaches it.',
        'actions'     => $canAdd
            ? '<button type="button" class="' . $primary . '" data-dialog-open="offering-add" data-dialog-title="Add an offering" data-dialog-action="' . esc(site_url($base)) . '" data-dialog-label="Add offering">Add an offering</button>'
            : '',
    ], ['saveData' => false]) ?>

    <?php if (! $offeringCanEdit): ?>
        <?= view('components/lock-note', ['kind' => 'read_only'], ['saveData' => false]) ?>
    <?php elseif ($offeringOptions['courses'] === []): ?>
        <?= view('components/alert', ['tone' => 'info', 'title' => 'Add a course first', 'text' => 'An offering is a course taught in a term, and there is no active course yet. Add one on the Courses tab.'], ['saveData' => false]) ?>
    <?php elseif ($offeringOptions['terms'] === []): ?>
        <?= view('components/alert', ['tone' => 'info', 'title' => 'Add a term first', 'text' => 'Offerings go into an upcoming or active term, and there is none yet. Add one on the Terms tab.'], ['saveData' => false]) ?>
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
            ['type' => 'search', 'name' => 'q', 'label' => 'Search', 'placeholder' => 'Course code, title or section', 'value' => $offeringFilters['q'], 'span' => 'lg:col-span-2'],
            ['type' => 'select', 'name' => 'term', 'label' => 'Term', 'options' => $termOptions, 'value' => $offeringFilters['term'] > 0 ? (string) $offeringFilters['term'] : ''],
            ['type' => 'select', 'name' => 'program', 'label' => 'Program', 'options' => $programOptions, 'value' => $offeringFilters['program'] > 0 ? (string) $offeringFilters['program'] : ''],
            ['type' => 'select', 'name' => 'instructor', 'label' => 'Instructor', 'options' => $instructorOptions, 'value' => $offeringFilters['instructor']],
            ['type' => 'select', 'name' => 'status', 'label' => 'Status', 'options' => ['' => 'Any status', 'active' => 'Active', 'completed' => 'Completed', 'archived' => 'Archived'], 'value' => $offeringFilters['status']],
        ],
    ], ['saveData' => false]) ?>

    <p class="text-sm text-[#646464]" aria-live="polite" data-offering-total>
        <?= esc(number_format($offeringTotal)) ?> <?= $offeringTotal === 1 ? 'offering' : 'offerings' ?><?= $filtered ? ' match' : '' ?>
    </p>

    <?php // Only the table scrolls; the heading, the filters and the count stay put (lg and up). ?>
    <div class="flex flex-col gap-6" data-panel-scroll="inner">

    <?= view('components/data-table', [
        'fill'       => true, // the table scrolls under its own heading row
        'caption'    => 'Offerings',
        'columns'    => [
            ['key' => 'course', 'label' => 'Course', 'sortable' => true],
            ['key' => 'section', 'label' => 'Section', 'sortable' => true],
            ['key' => 'term', 'label' => 'Term', 'sortable' => true],
            ['key' => 'meets', 'label' => 'Meets'],
            ['key' => 'instructors', 'label' => 'Instructors'],
            ['key' => 'capacity', 'label' => 'Enrolled / capacity', 'sortable' => true],
            ['key' => 'status', 'label' => 'Status', 'sortable' => true],
        ],
        'rows'       => $rows,
        'sort'       => $sort,
        'dir'        => $dir,
        'sortUrl'    => $sortUrl,
        'emptyIcon'  => 'modules',
        'emptyTitle' => $filtered ? 'No offerings match these filters' : 'No offerings yet',
        'emptyText'  => $filtered ? 'Try a different search, term, program or instructor.' : ($canAdd ? 'Add the first offering to open a course in a term.' : 'Offerings will be listed here.'),
    ], ['saveData' => false]) ?>

    </div>

    <?= view('components/pagination', ['page' => $offeringPage, 'pages' => $offeringPages, 'total' => $offeringTotal, 'baseUrl' => site_url($base), 'query' => $query], ['saveData' => false]) ?>

</div>

</div>

<?php if ($offeringCanEdit): ?>
    <?php
    $field = static fn (array $f): string => view('components/form-field', $f, ['saveData' => false]);

    $buttons = static fn (string $label): string => '<div class="flex flex-wrap items-center justify-end gap-3">'
        . '<button type="button" data-dialog-close class="rounded-full border border-gray-300 px-5 py-2 text-sm font-semibold text-[#3D3D3D] transition hover:bg-gray-50">Cancel</button>'
        . '<button type="submit" data-dialog-label class="rounded-full bg-brand px-5 py-2 text-sm font-semibold text-white transition hover:bg-brand-dark">' . esc($label) . '</button></div>';

    $form = static fn (string $inside, string $label): string => '<form method="post" action="" data-dialog-form class="flex flex-col gap-4">' . csrf_field()
        . $inside . '<p class="text-xs font-medium text-[#C33939]" data-form-error hidden></p>' . $buttons($label) . '</form>';

    $courseSelect = ['' => 'Choose a course'];

    foreach ($offeringOptions['courses'] as $c) {
        $courseSelect[(string) $c['id']] = $c['code'] . ' · ' . $c['title'];
    }

    $termSelect = ['' => 'Choose a term'];

    foreach ($offeringOptions['terms'] as $t) {
        $termSelect[(string) $t['id']] = $t['name'] . ' ' . $t['year'] . ($t['status'] === 'active' ? ' (active)' : '');
    }

    // Built per dialog: each needs its own field ids.
    $details = static fn (): string => $field(['name' => 'section_code', 'label' => 'Section', 'required' => true, 'maxlength' => 50, 'attrs' => 'autocomplete="off"', 'help' => 'For example A, or 1A. Shown in capitals.'])
        . $field(['name' => 'capacity', 'label' => 'Capacity', 'required' => true, 'type' => 'number', 'value' => '40', 'attrs' => 'min="1" max="1000" inputmode="numeric"', 'help' => 'How many students the section takes.'])
        . $field(['name' => 'description', 'label' => 'Description', 'type' => 'textarea', 'maxlength' => 5000, 'help' => 'Optional.']);

    $candidateSelect = ['' => $offeringInstructors === [] ? 'No one holds the instructor role yet' : 'Choose a person'];

    foreach ($offeringInstructors as $i) {
        $candidateSelect[(string) $i['id']] = $i['name'] . ' (' . $i['email'] . ')';
    }
    ?>

    <?php if ($canAdd): ?>
    <?= view('components/dialog', ['id' => 'offering-add', 'title' => 'Add an offering', 'body' => $form(
        $field(['name' => 'course_id', 'label' => 'Course', 'type' => 'select', 'options' => $courseSelect, 'required' => true])
        . $field(['name' => 'term_id', 'label' => 'Term', 'type' => 'select', 'options' => $termSelect, 'required' => true])
        . $details(),
        'Add offering',
    )], ['saveData' => false]) ?>
    <?php endif; ?>

    <?= view('components/dialog', ['id' => 'offering-edit', 'title' => 'Edit offering', 'body' => $form($details(), 'Save')], ['saveData' => false]) ?>

    <?= view('components/dialog', ['id' => 'offering-instructor', 'title' => 'Assign an instructor', 'body' => $form(
        $field(['name' => 'user_id', 'label' => 'Instructor', 'type' => 'select', 'options' => $candidateSelect, 'required' => true, 'help' => 'People who hold the instructor role in your institution.']),
        'Assign',
    )], ['saveData' => false]) ?>

    <?= view('components/confirm', [
        'id'           => 'offering-confirm',
        'title'        => 'Are you sure?',
        'text'         => 'This changes the offering.',
        'action'       => site_url($base),
        'confirmLabel' => 'Confirm',
        'tone'         => 'danger',
    ], ['saveData' => false]) ?>
<?php endif; ?>

<?= $this->endSection() ?>
