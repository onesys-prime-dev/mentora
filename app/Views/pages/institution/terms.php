<?php
/**
 * The institution's academic terms.
 *
 * @var list<array<string, mixed>> $termRows     the terms that match the filters
 * @var list<array<string, mixed>> $termAll      every term (the running one is drawn from these)
 * @var list<string>               $termYears    the academic years there are terms in
 * @var array<string, string>      $termFilters  q, year and status
 * @var bool                       $termCanEdit
 */
$base    = 'institution/terms';
$primary = 'rounded-full bg-brand px-5 py-2 text-sm font-semibold text-white transition hover:bg-brand-dark focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/50';
$link    = 'text-sm font-semibold text-brand hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/50 rounded';

$tones  = ['upcoming' => 'info', 'active' => 'success', 'completed' => 'neutral', 'archived' => 'neutral'];
$filtered = $termFilters['q'] !== '' || $termFilters['year'] !== '' || $termFilters['status'] !== '';

$yearOptions = ['' => 'Any year'];

foreach ($termYears as $year) {
    $yearOptions[$year] = $year;
}

$labels = ['upcoming' => 'Upcoming', 'active' => 'Active', 'completed' => 'Completed', 'archived' => 'Archived'];
$json   = static fn (array $values): string => esc(json_encode($values, JSON_UNESCAPED_UNICODE));
$date   = static fn (string $d): string => date('j M Y', strtotime($d));

$rows = [];

// One active term at a time (QA AMB-INST-02): a closed term is reopened only while none is active.
$activeTerm = null;

foreach ($termRows as $row) {
    if ($row['status'] === 'active') {
        $activeTerm = $row;
    }
}

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

        if ($term['close_snapshot'] !== null && (int) $term['copied_count'] === 0 && $activeTerm === null) {
            $actions .= ' <form class="ml-3 inline" method="post" action="' . esc(site_url($base . '/' . $term['id'] . '/reopen')) . '">' . csrf_field()
                . '<button type="submit" class="' . $link . '">Reopen</button></form>';
        } elseif ($term['close_snapshot'] !== null && (int) $term['copied_count'] === 0) {
            $actions .= ' <span class="ml-3 text-xs text-[#646464]" data-active-term-note>' . esc($activeTerm['name']) . ' is the active term; close it to reopen this one.</span>';
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

// The running term gets a card of its own above the list: where it is in its dates, and its actions.
$current = null;

foreach ($termAll as $term) {
    if ($term['status'] === 'active') {
        $current = $term;
        break;
    }
}

if ($current !== null) {
    $startAt   = strtotime((string) $current['start_date']);
    $endAt     = strtotime((string) $current['end_date']);
    $span      = max(1, $endAt - $startAt);
    $progress  = (int) round(min(1, max(0, (time() - $startAt) / $span)) * 100);
    $weeks     = max(1, (int) ceil($span / 604800));
    $weekNow   = min($weeks, max(1, (int) floor((time() - $startAt) / 604800) + 1));
}
?>

<?= $this->extend('layouts/app') ?>

<?= $this->section('content') ?>

<div class="py-3">

<?php // The way back stays on the background, above the panel, not in the box. ?>
<?= view('components/back-link', ['backUrl' => site_url('institution/dashboard'), 'backLabel' => 'Back to home'], ['saveData' => false]) ?>

<div class="flex flex-col gap-6 rounded-3xl bg-white p-5 shadow-sm sm:p-8" data-page-panel>

    <?= view('components/page-head', [
        'title'       => 'Terms',
        'icon'        => 'calendar',
        'description' => 'The periods your offerings run in. One is usually active at a time.',
        'actions'     => $termCanEdit
            ? '<a class="rounded-full border border-brand bg-white px-5 py-2 text-sm font-semibold text-brand transition hover:bg-brand-tint focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/50" href="' . esc(site_url($base . '/copy')) . '">Copy offerings</a> '
                . '<button type="button" class="' . $primary . '" data-dialog-open="term-form" data-dialog-title="Add a term" data-dialog-action="' . esc(site_url($base)) . '" data-dialog-label="Add term">Add a term</button>'
            : '',
    ], ['saveData' => false]) ?>

    <?php if (! $termCanEdit): ?>
        <?= view('components/lock-note', ['kind' => 'read_only'], ['saveData' => false]) ?>
    <?php endif; ?>

    <?php if (session()->has('message')): ?>
        <?= view('components/alert', ['tone' => str_contains((string) session('message'), 'allowed, but') ? 'warning' : 'success', 'text' => (string) session('message')], ['saveData' => false]) ?>
    <?php endif; ?>
    <?php if (session()->has('error')): ?>
        <?= view('components/alert', ['tone' => 'danger', 'text' => (string) session('error')], ['saveData' => false]) ?>
    <?php endif; ?>

    <?php if ($current !== null): ?>
        <section class="flex flex-wrap items-center justify-between gap-x-8 gap-y-4 rounded-2xl border border-brand/15 bg-linear-to-br from-brand/10 to-brand/[0.03] px-6 py-5" aria-label="Current term" data-current-term="<?= esc((string) $current['id']) ?>">
            <div class="flex min-w-0 flex-col gap-1">
                <span class="text-xs font-bold uppercase tracking-wide text-[#646464]">Current term</span>
                <h2 class="flex flex-wrap items-center gap-3 text-lg font-bold text-brand">
                    <?= esc($current['name']) ?> · A.Y. <?= esc((string) $current['academic_year']) ?>
                    <?= view('components/status-badge', ['label' => 'Active', 'tone' => 'success'], ['saveData' => false]) ?>
                </h2>
                <p class="text-sm text-[#646464]">
                    <?= esc((string) $current['offering_count']) ?> <?= (int) $current['offering_count'] === 1 ? 'offering' : 'offerings' ?> ·
                    <?= esc((string) $current['enrolment_count']) ?> enrolled
                </p>
            </div>

            <div class="flex min-w-56 max-w-md flex-1 flex-col gap-1.5">
                <span class="text-sm text-[#646464]">Week <?= esc((string) $weekNow) ?> of <?= esc((string) $weeks) ?></span>
                <div class="h-2 overflow-hidden rounded-full bg-brand/15" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?= esc((string) $progress) ?>" aria-label="Progress through the term">
                    <div class="h-full rounded-full bg-brand" style="width: <?= esc((string) $progress) ?>%"></div>
                </div>
                <div class="flex justify-between text-xs text-[#646464]"><span><?= esc($date((string) $current['start_date'])) ?></span><span><?= esc($date((string) $current['end_date'])) ?></span></div>
            </div>

            <?php if ($termCanEdit): ?>
                <div class="flex flex-wrap items-center gap-3">
                    <button type="button" class="<?= $link ?>" data-dialog-open="term-form"
                        data-dialog-title="Edit <?= esc($current['name']) ?>" data-dialog-label="Save"
                        data-dialog-action="<?= esc(site_url($base . '/' . $current['id'])) ?>"
                        data-dialog-values="<?= $json(['name' => $current['name'], 'academic_year' => $current['academic_year'], 'start_date' => $current['start_date'], 'end_date' => $current['end_date']]) ?>">Edit dates</button>
                    <a class="rounded-full border border-brand bg-white px-4 py-1.5 text-sm font-semibold text-brand transition hover:bg-brand/10 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/50" href="<?= esc(site_url($base . '/' . $current['id'] . '/close')) ?>">Close term</a>
                </div>
            <?php endif; ?>
        </section>
    <?php endif; ?>

    <?= view('components/toolbar', [
        'surface'  => 'tinted', // on the page's white panel a white card would not stand out
        'inline'   => true,
        'action'   => site_url($base),
        'filtered' => $filtered,
        'fields'   => [
            ['type' => 'search', 'name' => 'q', 'label' => 'Search', 'placeholder' => 'Term name or academic year', 'value' => $termFilters['q'], 'span' => 'lg:col-span-2'],
            ['type' => 'select', 'name' => 'year', 'label' => 'Academic year', 'options' => $yearOptions, 'value' => $termFilters['year']],
            ['type' => 'select', 'name' => 'status', 'label' => 'Status', 'options' => ['' => 'Any status'] + $labels, 'value' => $termFilters['status']],
        ],
    ], ['saveData' => false]) ?>

    <p class="text-sm text-[#646464]" aria-live="polite" data-term-total>
        <?= esc((string) count($termRows)) ?> <?= count($termRows) === 1 ? 'term' : 'terms' ?><?= $filtered ? ' match' : '' ?>
    </p>

    <?php // Only the table scrolls; the heading, the filters and the count stay put (lg and up). ?>
    <div class="flex flex-col gap-6" data-panel-scroll="inner">

    <?= view('components/data-table', [
        'fill'       => true, // the table scrolls under its own heading row
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
        'emptyTitle' => $filtered ? 'No terms match these filters' : 'No terms yet',
        'emptyText'  => $filtered ? 'Try a different search, academic year or status.' : ($termCanEdit ? 'Add your first term, then start it when it begins.' : 'Terms will be listed here.'),
    ], ['saveData' => false]) ?>

    </div>

</div>

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
        . '<button type="submit" data-dialog-label class="rounded-full bg-brand px-5 py-2 text-sm font-semibold text-white transition hover:bg-brand-dark">Save</button>'
        . '</div></form>';
    ?>

    <?= view('components/dialog', ['id' => 'term-form', 'title' => 'Term', 'body' => $body], ['saveData' => false]) ?>
<?php endif; ?>

<?= $this->endSection() ?>
