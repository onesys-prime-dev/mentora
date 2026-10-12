<?php
/**
 * The institution's academic terms.
 *
 * @var list<array<string, mixed>> $termRows
 * @var bool                       $termCanEdit
 */
$base    = 'institution/terms';
$primary = 'rounded-full bg-[#0040A6] px-5 py-2 text-sm font-semibold text-white transition hover:bg-[#002068] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#0040A6]/50';
$link    = 'text-sm font-semibold text-[#0040A6] hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#0040A6]/50 rounded';

$tones  = ['upcoming' => 'info', 'active' => 'success', 'completed' => 'neutral', 'archived' => 'neutral'];
$labels = ['upcoming' => 'Upcoming', 'active' => 'Active', 'completed' => 'Completed', 'archived' => 'Archived'];
$json   = static fn (array $values): string => esc(json_encode($values, JSON_UNESCAPED_UNICODE));
$date   = static fn (string $d): string => date('j M Y', strtotime($d));

$rows = [];

foreach ($termRows as $term) {
    $closed = in_array($term['status'], ['completed', 'archived'], true);

    $actions = '';

    if ($termCanEdit && ! $closed) {
        $actions .= '<button type="button" class="' . $link . '" data-dialog-open="term-form"'
            . ' data-dialog-title="Edit ' . esc($term['name']) . '" data-dialog-label="Save"'
            . ' data-dialog-action="' . esc(site_url($base . '/' . $term['id'])) . '"'
            . ' data-dialog-values="' . $json(['name' => $term['name'], 'academic_year' => $term['academic_year'], 'start_date' => $term['start_date'], 'end_date' => $term['end_date']]) . '">Edit</button>';

        if ($term['status'] === 'upcoming') {
            $actions .= ' <form class="ml-3 inline" method="post" action="' . esc(site_url($base . '/' . $term['id'] . '/start')) . '">' . csrf_field()
                . '<button type="submit" class="' . $link . '">Start term</button></form>';
        } else {
            $actions .= ' <form class="ml-3 inline" method="post" action="' . esc(site_url($base . '/' . $term['id'] . '/upcoming')) . '">' . csrf_field()
                . '<button type="submit" class="' . $link . '">Back to upcoming</button></form>';
        }

        $actions .= ' <a class="ml-3 ' . $link . '" href="' . esc(site_url($base . '/' . $term['id'] . '/close')) . '">Close term</a>';
    }

    if ($termCanEdit && $term['status'] === 'completed') {
        $actions .= '<a class="' . $link . '" href="' . esc(site_url($base . '/copy') . '?source=' . $term['id']) . '">Copy offerings</a>';

        if ($term['close_snapshot'] !== null && (int) $term['copied_count'] === 0) {
            $actions .= ' <form class="ml-3 inline" method="post" action="' . esc(site_url($base . '/' . $term['id'] . '/reopen')) . '">' . csrf_field()
                . '<button type="submit" class="' . $link . '">Reopen</button></form>';
        } elseif ($term['close_snapshot'] !== null) {
            $actions .= ' <span class="ml-3 text-xs text-[#646464]" data-copied-note>Copied into another term, so it stays closed.</span>';
        }
    }

    $overlap = $term['overlaps'] === []
        ? ''
        : ' <span class="ml-2 inline-flex items-center gap-1 rounded-full bg-[#FFF1D6] px-2 py-0.5 text-[11px] font-bold text-[#946200]" data-overlap="' . esc(implode(', ', $term['overlaps'])) . '" title="Overlaps ' . esc(implode(', ', $term['overlaps'])) . '">'
            . icon('attention', 'h-3 w-3 text-current') . 'Overlaps</span>';

    $rows[] = [
        'id'      => $term['id'],
        'label'   => $term['name'],
        'cells'   => [
            'name'      => ['html' => '<span class="font-semibold">' . esc($term['name']) . '</span>' . $overlap],
            'year'      => (string) $term['academic_year'],
            'dates'     => $date((string) $term['start_date']) . ' to ' . $date((string) $term['end_date']),
            'offerings' => (string) $term['offering_count'],
            'enrolments' => (string) $term['enrolment_count'],
            'status'    => ['html' => view('components/status-badge', ['label' => $labels[$term['status']] ?? ucfirst((string) $term['status']), 'tone' => $tones[$term['status']] ?? 'neutral'], ['saveData' => false])],
        ],
        'actions' => $actions,
    ];
}
?>

<?= $this->extend('layouts/app') ?>

<?= $this->section('content') ?>

<div class="flex flex-col gap-6 py-3">

    <?= view('components/page-head', [
        'title'       => 'Terms',
        'icon'        => 'calendar',
        'description' => 'The periods your offerings run in. One is usually active at a time.',
        'backUrl'     => site_url('institution/dashboard'),
        'backLabel'   => 'Back to home',
        'actions'     => $termCanEdit
            ? '<a class="rounded-full border border-[#0040A6] bg-white px-5 py-2 text-sm font-semibold text-[#0040A6] transition hover:bg-[#F2F7FF] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#0040A6]/50" href="' . esc(site_url($base . '/copy')) . '">Copy offerings</a> '
                . '<button type="button" class="' . $primary . '" data-dialog-open="term-form" data-dialog-title="Add a term" data-dialog-action="' . esc(site_url($base)) . '" data-dialog-label="Add term">Add a term</button>'
            : '',
    ], ['saveData' => false]) ?>

    <?= view('components/tabs', ['ariaLabel' => 'Academics', 'tabs' => [
        ['label' => 'Structure', 'href' => site_url('institution/structure')],
        ['label' => 'Courses', 'href' => site_url('institution/courses')],
        ['label' => 'Terms', 'href' => site_url($base), 'active' => true],
        ['label' => 'Offerings', 'href' => site_url('institution/offerings')],
    ]], ['saveData' => false]) ?>

    <?php if (! $termCanEdit): ?>
        <?= view('components/lock-note', ['kind' => 'read_only'], ['saveData' => false]) ?>
    <?php endif; ?>

    <?php if (session()->has('message')): ?>
        <?= view('components/alert', ['tone' => str_contains((string) session('message'), 'allowed, but') ? 'warning' : 'success', 'text' => (string) session('message')], ['saveData' => false]) ?>
    <?php endif; ?>
    <?php if (session()->has('error')): ?>
        <?= view('components/alert', ['tone' => 'danger', 'text' => (string) session('error')], ['saveData' => false]) ?>
    <?php endif; ?>

    <?= view('components/data-table', [
        'caption'    => 'Academic terms',
        'columns'    => [
            ['key' => 'name', 'label' => 'Term'],
            ['key' => 'year', 'label' => 'Academic year'],
            ['key' => 'dates', 'label' => 'Dates'],
            ['key' => 'offerings', 'label' => 'Offerings'],
            ['key' => 'enrolments', 'label' => 'Enrolled'],
            ['key' => 'status', 'label' => 'Status'],
        ],
        'rows'       => $rows,
        'emptyIcon'  => 'calendar',
        'emptyTitle' => 'No terms yet',
        'emptyText'  => $termCanEdit ? 'Add your first term, then start it when it begins.' : 'Terms will be listed here.',
    ], ['saveData' => false]) ?>

</div>

<?php if ($termCanEdit): ?>
    <?php
    $field = static fn (array $f): string => view('components/form-field', $f, ['saveData' => false]);

    $body = '<form method="post" action="" data-dialog-form class="flex flex-col gap-4">' . csrf_field()
        . $field(['name' => 'name', 'label' => 'Name', 'required' => true, 'maxlength' => 100, 'attrs' => 'autocomplete="off"', 'help' => 'For example First Semester.'])
        . $field(['name' => 'academic_year', 'label' => 'Academic year', 'required' => true, 'maxlength' => 20, 'attrs' => 'autocomplete="off" placeholder="2026-2027"', 'help' => 'Written 2026-2027, or just 2026.'])
        . '<div class="grid gap-4 sm:grid-cols-2">'
        . $field(['name' => 'start_date', 'label' => 'Starts', 'type' => 'date', 'required' => true])
        . $field(['name' => 'end_date', 'label' => 'Ends', 'type' => 'date', 'required' => true])
        . '</div>'
        . '<p class="text-xs font-medium text-[#C33939]" data-form-error hidden></p>'
        . '<div class="flex flex-wrap items-center justify-end gap-3">'
        . '<button type="button" data-dialog-close class="rounded-full border border-gray-300 px-5 py-2 text-sm font-semibold text-[#3D3D3D] transition hover:bg-gray-50">Cancel</button>'
        . '<button type="submit" data-dialog-label class="rounded-full bg-[#0040A6] px-5 py-2 text-sm font-semibold text-white transition hover:bg-[#002068]">Save</button>'
        . '</div></form>';
    ?>

    <?= view('components/dialog', ['id' => 'term-form', 'title' => 'Term', 'body' => $body], ['saveData' => false]) ?>
<?php endif; ?>

<?= $this->endSection() ?>
