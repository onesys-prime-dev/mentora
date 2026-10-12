<?php
/**
 * Who is enrolled in one offering.
 *
 * @var array<string, mixed>       $rosterOffering
 * @var list<array<string, mixed>> $rosterRows
 * @var int                        $rosterTotal
 * @var int                        $rosterPages
 * @var int                        $rosterPage
 * @var array<string, int>         $rosterCounts
 * @var bool                       $rosterLocked   the SIS manages this course
 * @var array<string, mixed>       $rosterFilters
 * @var bool                       $rosterCanEdit
 */
$o       = $rosterOffering;
$base    = 'institution/enrolments/offering/' . $o['id'];
$label   = $o['course_code'] . ' ' . $o['section_code'];
$primary = 'rounded-full bg-[#0040A6] px-5 py-2 text-sm font-semibold text-white transition hover:bg-[#002068] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#0040A6]/50';
$link    = 'text-sm font-semibold text-[#0040A6] hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#0040A6]/50 rounded';

$open     = $o['status'] === 'active';
$canEdit  = $rosterCanEdit && $open;
$filtered = $rosterFilters['q'] !== '' || $rosterFilters['status'] !== '';

$query = array_filter([
    'q'      => $rosterFilters['q'],
    'status' => $rosterFilters['status'],
    'sort'   => $rosterFilters['sort'],
    'dir'    => $rosterFilters['dir'],
], static fn ($v): bool => $v !== null && $v !== '');

$sort = $rosterFilters['sort'] !== '' ? $rosterFilters['sort'] : 'name';
$dir  = $rosterFilters['dir'] === 'desc' ? 'desc' : 'asc';

$sortUrl = static fn (string $column, string $next): string => site_url($base) . '?' . http_build_query(array_merge($query, ['sort' => $column, 'dir' => $next, 'page' => null]));

$tones  = ['enrolled' => 'success', 'dropped' => 'warning', 'withdrawn' => 'neutral', 'completed' => 'info'];
$json   = static fn (array $values): string => esc(json_encode($values, JSON_UNESCAPED_UNICODE));
$labels = ['enrolled' => 'Enrolled', 'dropped' => 'Dropped', 'withdrawn' => 'Withdrawn', 'completed' => 'Completed'];

$rows = [];

foreach ($rosterRows as $r) {
    $action = '';

    if ($canEdit && $r['locked']) {
        $action = '<span class="inline-flex items-center gap-1 text-xs text-[#646464]" data-sis-locked>' . icon('lock', 'h-3.5 w-3.5') . 'Managed by the SIS</span>';
    } elseif ($canEdit) {
        $action = '<button type="button" class="' . $link . '" data-dialog-open="roster-status"'
            . ' data-dialog-title="Change the place of ' . esc($r['name']) . '" data-dialog-label="Save"'
            . ' data-dialog-action="' . esc(site_url('institution/enrolments/' . $r['id'] . '/status')) . '"'
            . ' data-dialog-values="' . $json(['status' => $r['status']]) . '">Change status</button>';
    }

    $rows[] = [
        'id'      => $r['id'],
        'label'   => $r['name'],
        'cells'   => [
            'name'   => ['html' => '<span class="font-medium">' . esc($r['name']) . '</span>' . ($r['email'] !== '' && $r['email'] !== $r['name'] ? '<span class="block text-xs text-[#646464]">' . esc($r['email']) . '</span>' : '')],
            'number' => $r['student_number'] !== '' ? $r['student_number'] : '—',
            'status' => ['html' => view('components/status-badge', ['label' => $labels[$r['status']] ?? ucfirst($r['status']), 'tone' => $tones[$r['status']] ?? 'neutral'], ['saveData' => false])],
            'since'  => date('j M Y', strtotime($r['enrolled_at'])),
        ],
        'actions' => $action,
    ];
}

$enrolled = (int) $o['enrolled_count'];
$capacity = (int) $o['capacity'];
?>

<?= $this->extend('layouts/app') ?>

<?= $this->section('content') ?>

<div class="flex flex-col gap-6 py-3">

    <?= view('components/page-head', [
        'title'       => $label . ' roster',
        'icon'        => 'user',
        'description' => $o['course_title'] . ' · ' . $o['term_name'] . ' ' . $o['academic_year'],
        'backUrl'     => site_url('institution/offerings'),
        'backLabel'   => 'Back to offerings',
        'actions'     => $canEdit
            ? '<button type="button" class="' . $primary . '" data-dialog-open="roster-add" data-dialog-title="Add students to ' . esc($label) . '" data-dialog-action="' . esc(site_url($base)) . '" data-dialog-label="Enrol students">Add students</button>'
            : '',
    ], ['saveData' => false]) ?>

    <?php if (! $rosterCanEdit): ?>
        <?= view('components/lock-note', ['kind' => 'read_only'], ['saveData' => false]) ?>
    <?php elseif (! $open): ?>
        <?= view('components/lock-note', ['kind' => 'closed', 'text' => 'This offering is ' . $o['status'] . ', so its roster cannot be changed. You can still look at it.'], ['saveData' => false]) ?>
    <?php elseif ($rosterLocked): ?>
        <?= view('components/lock-note', ['kind' => 'sis', 'text' => 'Your student information system manages this course. Places of students it knows are changed there; you can still enrol other students.'], ['saveData' => false]) ?>
    <?php endif; ?>

    <?php if (session()->has('message')): ?>
        <?= view('components/alert', ['tone' => 'success', 'text' => (string) session('message')], ['saveData' => false]) ?>
    <?php endif; ?>
    <?php if (session()->has('error')): ?>
        <?= view('components/alert', ['tone' => 'danger', 'text' => (string) session('error')], ['saveData' => false]) ?>
    <?php endif; ?>

    <section class="rounded-2xl border border-[#A9A9A9]/50 bg-white p-5" aria-label="Places">
        <?= view('components/usage-bar', ['label' => 'Places taken', 'used' => $enrolled, 'limit' => $capacity], ['saveData' => false]) ?>
        <?php if ($enrolled > $capacity): ?>
            <p class="mt-2 text-xs font-semibold text-[#946200]" data-over-capacity>Over capacity by <?= esc((string) ($enrolled - $capacity)) ?>.</p>
        <?php endif; ?>
        <p class="mt-3 text-xs text-[#646464]">
            <?= esc((string) $rosterCounts['dropped']) ?> dropped · <?= esc((string) $rosterCounts['withdrawn']) ?> withdrawn · <?= esc((string) $rosterCounts['completed']) ?> completed
        </p>
    </section>

    <?= view('components/toolbar', [
        'action'   => site_url($base),
        'filtered' => $filtered,
        'fields'   => [
            ['type' => 'search', 'name' => 'q', 'label' => 'Search', 'placeholder' => 'Name, student number or email', 'value' => $rosterFilters['q'], 'span' => 'lg:col-span-2'],
            ['type' => 'select', 'name' => 'status', 'label' => 'Status', 'options' => ['' => 'Any status'] + $labels, 'value' => $rosterFilters['status']],
        ],
    ], ['saveData' => false]) ?>

    <p class="text-sm text-[#646464]" aria-live="polite" data-roster-total>
        <?= esc(number_format($rosterTotal)) ?> <?= $rosterTotal === 1 ? 'student' : 'students' ?><?= $filtered ? ' match' : '' ?>
    </p>

    <?= view('components/data-table', [
        'caption'    => 'Roster of ' . $label,
        'columns'    => [
            ['key' => 'name', 'label' => 'Student', 'sortable' => true],
            ['key' => 'number', 'label' => 'Student number', 'sortable' => true],
            ['key' => 'status', 'label' => 'Status', 'sortable' => true],
            ['key' => 'since', 'label' => 'Since', 'sortable' => true],
        ],
        'rows'       => $rows,
        'sort'       => $sort,
        'dir'        => $dir,
        'sortUrl'    => $sortUrl,
        'emptyIcon'  => 'user',
        'emptyTitle' => $filtered ? 'No students match these filters' : 'No students enrolled yet',
        'emptyText'  => $filtered ? 'Try a different name, number or status.' : ($canEdit ? 'Add students by their student number or email address.' : 'Students will be listed here.'),
    ], ['saveData' => false]) ?>

    <?= view('components/pagination', ['page' => $rosterPage, 'pages' => $rosterPages, 'total' => $rosterTotal, 'baseUrl' => site_url($base), 'query' => $query], ['saveData' => false]) ?>

</div>

<?php if ($canEdit): ?>
    <?php
    $field = static fn (array $f): string => view('components/form-field', $f, ['saveData' => false]);

    $buttons = static fn (string $text): string => '<div class="flex flex-wrap items-center justify-end gap-3">'
        . '<button type="button" data-dialog-close class="rounded-full border border-gray-300 px-5 py-2 text-sm font-semibold text-[#3D3D3D] transition hover:bg-gray-50">Cancel</button>'
        . '<button type="submit" data-dialog-label class="rounded-full bg-[#0040A6] px-5 py-2 text-sm font-semibold text-white transition hover:bg-[#002068]">' . esc($text) . '</button></div>';

    $form = static fn (string $inside, string $text): string => '<form method="post" action="" data-dialog-form class="flex flex-col gap-4">' . csrf_field()
        . $inside . '<p class="text-xs font-medium text-[#C33939]" data-form-error hidden></p>' . $buttons($text) . '</form>';

    // Two dialogs ask the same thing; the wording differs so their field ids do too.
    $over = fn (string $text): string => $field(['name' => 'over_capacity', 'label' => $text, 'type' => 'checkbox', 'help' => $capacity . ' places, ' . $enrolled . ' taken.']);
    ?>

    <?= view('components/dialog', ['id' => 'roster-add', 'title' => 'Add students', 'body' => $form(
        $field(['name' => 'students', 'label' => 'Student numbers or email addresses', 'type' => 'textarea', 'required' => true, 'help' => 'One per line, or separated by commas. Up to 500 at once. Students who cannot be enrolled are skipped and listed.'])
        . $over('Enrol even if the section goes over capacity'),
        'Enrol students',
    )], ['saveData' => false]) ?>

    <?= view('components/dialog', ['id' => 'roster-status', 'title' => 'Change status', 'body' => $form(
        $field(['name' => 'status', 'label' => 'Status', 'type' => 'select', 'options' => $labels, 'required' => true, 'help' => 'Dropping or withdrawing keeps the student\'s work and grades.'])
        . $over('Allow this even if the section is over capacity'),
        'Save',
    )], ['saveData' => false]) ?>
<?php endif; ?>

<?= $this->endSection() ?>
