<?php
/**
 * The dean's Approvals: grade-override requests of the department. "Waiting for you" are the ones the
 * chairperson endorsed (or that had no chairperson to ask); each opens a dialog with how the student's
 * grade is made up, and Approve (the grade is changed) or Reject (with a reason). "Decided" is the history.
 * A student's work is not shown, only the numbers asked for and the reason.
 *
 * @var App\Services\Courses\Grades\OverrideApprovalFilters $filters
 * @var list<array<string, mixed>> $requests
 */
use App\Services\Courses\Grades\OverrideRequestChain;

$base = site_url('dean/approvals');

$pct = static fn ($value): string => $value === null ? '—' : rtrim(rtrim(number_format((float) $value, 1), '0'), '.') . '%';

$age = static function (string $at): string {
    $days = (int) floor((time() - strtotime($at)) / 86400);

    return $days < 1 ? 'today' : $days . ' d';
};

$options = static function (array $rows, string $any): array {
    $list = ['' => $any];

    foreach ($rows as $row) {
        $list[(string) $row['id']] = trim((string) $row['name']);
    }

    return $list;
};

$decided = $filters->view === 'decided';
$tabLink = static fn (string $view): string => $base . (($query = http_build_query($filters->toQuery(['view' => $view === 'pending' ? null : $view]))) === '' ? '' : '?' . $query);

$rows = [];

foreach ($requests as $r) {
    $asked = $r['requested_percentage'] === null ? 'remove the override' : $pct($r['requested_percentage']);

    $cells = [
        'student'   => trim((string) $r['student_name']) ?: 'A student',
        'course'    => ['html' => esc($r['course_code'] . ' ' . $r['section_code']) . '<span class="block text-xs text-[#646464]">' . esc((string) $r['program_name']) . '</span>'],
        'requested' => ['html' => esc($pct($r['calculated_percentage'])) . ' → <strong>' . esc($asked) . '</strong>'],
        'requester' => ['html' => esc(trim((string) $r['requester_name']) ?: '—') . '<span class="block text-xs text-[#646464]">' . esc(ucfirst((string) $r['requester_role'])) . '</span>'],
    ];

    if ($decided) {
        $cells['age'] = ['html' => view('components/status-badge', ['label' => $r['status'] === OverrideRequestChain::APPROVED ? 'Approved' : 'Rejected', 'tone' => $r['status'] === OverrideRequestChain::APPROVED ? 'success' : 'danger'], ['saveData' => false])
            . '<span class="block text-xs text-[#646464]">' . esc(date('M j, Y', strtotime((string) $r['decided_at']))) . '</span>'];
    } else {
        $cells['age'] = $age((string) $r['created_at']);
    }

    $rows[] = [
        'id'      => (int) $r['id'],
        'cells'   => $cells,
        'actions' => $decided ? '' : '<button type="button" data-dialog-open="review-' . (int) $r['id'] . '" class="rounded-full bg-brand px-4 py-1.5 text-xs font-semibold text-white transition hover:bg-brand-dark">Review</button>',
    ];
}

$columns = [
    ['key' => 'student', 'label' => 'Student', 'sortable' => true],
    ['key' => 'course', 'label' => 'Course', 'sortable' => true],
    ['key' => 'requested', 'label' => 'Calculated → requested', 'sortable' => true],
    ['key' => 'requester', 'label' => 'Requested by', 'sortable' => true],
    ['key' => 'age', 'label' => $decided ? 'Outcome' : 'Age', 'sortable' => true],
];

// A header sorts by that column, keeping the tab and the filters; clicking it again reverses it.
$sortUrl = static fn (string $column, string $next): string => $base . '?' . http_build_query($filters->toQuery(['sort' => $column === 'age' ? null : $column, 'dir' => $next]));
?>

<?= $this->extend('layouts/app') ?>

<?= $this->section('content') ?>

<div class="py-3">

<?php // The way back stays on the background, above the panel, not in the box. ?>
<?= view('components/back-link', ['backUrl' => site_url('dean/dashboard'), 'backLabel' => 'Back to dashboard'], ['saveData' => false]) ?>

<div class="flex flex-col gap-6 rounded-3xl bg-white p-5 shadow-sm sm:p-8" data-page-panel>

    <?= view('components/page-head', [
        'title'       => 'Grade overrides',
        'icon'        => 'tasks',
        'description' => 'Requests to change a final grade in your department. Approving changes the grade; rejecting leaves it as it is.',
    ], ['saveData' => false]) ?>

    <div class="flex items-center gap-1 text-xs" role="group" aria-label="Show">
        <a href="<?= esc($tabLink('pending')) ?>" <?= ! $decided ? 'aria-current="true"' : '' ?> class="rounded-full px-3 py-1.5 font-semibold <?= ! $decided ? 'bg-brand text-white' : 'bg-gray-100 text-[#3D3D3D] hover:bg-gray-200' ?>">Waiting for you <span data-pending-count>(<?= (int) $pending ?>)</span></a>
        <a href="<?= esc($tabLink('decided')) ?>" <?= $decided ? 'aria-current="true"' : '' ?> class="rounded-full px-3 py-1.5 font-semibold <?= $decided ? 'bg-brand text-white' : 'bg-gray-100 text-[#3D3D3D] hover:bg-gray-200' ?>">Decided</a>
    </div>

    <?= view('components/toolbar', [
        'surface'  => 'tinted', // on the page's white panel a white card would not stand out
        'action'   => $base,
        'filtered' => $filters->isFiltered(),
        'clearUrl' => $decided ? $base . '?view=decided' : $base,
        'hidden'   => ($decided ? ['view' => 'decided'] : []) + ($filters->sort !== 'age' ? ['sort' => $filters->sort] : []) + ($filters->dir !== null ? ['dir' => $filters->dir] : []),
        'fields'   => [
            ['type' => 'search', 'name' => 'q', 'label' => 'Search', 'placeholder' => 'Student or course', 'value' => $filters->q ?? '', 'span' => 'sm:col-span-2'],
            ['type' => 'select', 'name' => 'program', 'label' => 'Program', 'options' => $options($programs, 'Any program'), 'value' => (string) ($filters->programId ?? '')],
            ['type' => 'select', 'name' => 'requested_by', 'label' => 'Requested by', 'options' => $options($requesters, 'Anyone'), 'value' => (string) ($filters->requesterId ?? '')],
        ],
    ], ['saveData' => false]) ?>

    <?php if ($loadError): ?>

        <div class="flex flex-col items-center justify-center gap-3 rounded-2xl border border-[#C33939]/30 bg-white px-8 py-14 text-center" role="alert" data-load-error>
            <?= icon('warning', 'h-10 w-10 text-[#C33939]') ?>
            <span class="text-sm font-semibold text-[#1A1C1E]">We could not load the requests</span>
            <span class="text-xs text-[#646464]">Something went wrong on our side. Nothing has been decided.</span>
            <a href="<?= esc($base) ?>" class="rounded-full bg-brand px-5 py-2 text-sm font-semibold text-white transition hover:bg-brand-dark focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/55 focus-visible:ring-offset-2">Try again</a>
        </div>

    <?php else: ?>

        <?= view('components/data-table', [
            'caption'    => $decided ? 'Grade-override requests you decided' : 'Grade-override requests waiting for you',
            'columns'    => $columns,
            'rows'       => $rows,
            'sort'       => $filters->sort,
            'dir'        => $filters->direction(),
            'sortUrl'    => $sortUrl,
            'emptyTitle' => $filters->isFiltered() ? 'No requests match these filters' : ($decided ? 'Nothing decided yet' : 'Nothing waiting for you'),
            'emptyText'  => $filters->isFiltered() ? 'Try a different search, program or requester.' : ($decided ? 'Requests you approve or reject are listed here.' : 'When a chairperson endorses a request to change a final grade in your department, it appears here.'),
            'emptyIcon'  => 'tasks',
        ], ['saveData' => false]) ?>

    <?php endif; ?>

    <?php if (! $decided && ! $loadError): ?>
        <?php
        $field = static fn (array $f): string => view('components/form-field', $f, ['saveData' => false]);

        $closeButton = '<button type="button" data-dialog-close class="rounded-full border border-gray-300 px-5 py-2 text-sm font-semibold text-[#3D3D3D] transition hover:bg-gray-50">Cancel</button>';
        ?>

        <?php foreach ($requests as $r): ?>
            <?php
            $id       = (int) $r['id'];
            $student  = trim((string) $r['student_name']) ?: 'the student';
            $course   = $r['course_code'] . ' ' . $r['section_code'];
            $removal  = $r['requested_percentage'] === null;
            $asked    = $pct($r['requested_percentage']);
            $was      = $pct($r['calculated_percentage']);
            $now      = $pct($r['current_calculated']);

            $consequence = $removal
                ? 'Approve removing the override for ' . $student . ' in ' . $course . '? The grade returns to the calculated ' . $now . '.'
                : 'Approve ' . $asked . ' for ' . $student . ' in ' . $course . '? This replaces the calculated ' . $now . '.';

            $breakdown = '';

            foreach ($r['breakdown'] ?? [] as $part) {
                $breakdown .= '<li class="flex justify-between gap-3"><span>' . esc($part['name']) . '</span><span class="font-semibold">' . esc($pct($part['percentage'])) . '</span></li>';
            }

            $details = '<dl class="grid grid-cols-[auto_1fr] gap-x-4 gap-y-1 text-sm">'
                . '<dt class="text-[#646464]">Calculated</dt><dd class="font-semibold">' . esc($now) . '</dd>'
                . '<dt class="text-[#646464]">Requested</dt><dd class="font-semibold">' . esc($removal ? 'remove the override' : $asked) . '</dd>'
                . '<dt class="text-[#646464]">Reason</dt><dd>' . esc((string) $r['reason']) . '</dd>'
                . '<dt class="text-[#646464]">Requested by</dt><dd>' . esc(trim((string) $r['requester_name'])) . ' (' . esc((string) $r['requester_role']) . ')</dd>'
                . ($r['endorsed_by'] !== null ? '<dt class="text-[#646464]">Endorsed by</dt><dd>' . esc(trim((string) $r['endorser_name'])) . ($r['endorse_comment'] ? ': “' . esc((string) $r['endorse_comment']) . '”' : '') . '</dd>' : '<dt class="text-[#646464]">Endorsed by</dt><dd>Nobody: no chairperson to ask</dd>')
                . '</dl>';

            $drift = $r['calculated_percentage'] !== null && $r['current_calculated'] !== null && abs((float) $r['calculated_percentage'] - (float) $r['current_calculated']) >= 0.05
                ? '<p class="rounded-xl bg-[#FFF1D6] px-3 py-2 text-xs font-medium text-[#6F4A00]" data-drift>The calculated grade changed from ' . esc($was) . ' to ' . esc($now) . ' since this was asked.</p>'
                : '';

            $body = '<div class="flex flex-col gap-4">' . $details . $drift
                . ($breakdown !== '' ? '<div><p class="mb-1 text-xs font-semibold uppercase tracking-wide text-[#646464]">How the grade is made up</p><ul class="flex flex-col gap-1 rounded-xl bg-gray-50 px-3 py-2 text-sm" data-breakdown>' . $breakdown . '</ul></div>' : '')

                . '<form method="post" action="' . esc(site_url("dean/approvals/{$id}/approve"), 'attr') . '" data-dialog-form class="flex flex-col gap-3 border-t border-gray-100 pt-4">' . csrf_field()
                . '<p class="text-sm font-medium text-[#1A1C1E]">' . esc($consequence) . '</p>'
                . '<p class="text-xs font-medium text-[#C33939]" data-form-error hidden></p>'
                . '<div class="flex justify-end"><button type="submit" data-dialog-label class="rounded-full bg-brand px-5 py-2 text-sm font-semibold text-white transition hover:bg-brand-dark">' . esc($removal ? 'Approve removal' : 'Approve ' . $asked) . '</button></div>'
                . '</form>'

                . '<form method="post" action="' . esc(site_url("dean/approvals/{$id}/reject"), 'attr') . '" data-dialog-form class="flex flex-col gap-3 border-t border-gray-100 pt-4">' . csrf_field()
                . $field(['name' => 'reason', 'label' => 'Or reject, and say why', 'type' => 'textarea', 'maxlength' => 2000, 'help' => esc(trim((string) $r['requester_name']) ?: 'The requester') . ' will read this. The grade stays as it is.'])
                . '<p class="text-xs font-medium text-[#C33939]" data-form-error hidden></p>'
                . '<div class="flex flex-wrap items-center justify-end gap-3">' . $closeButton . '<button type="submit" data-dialog-label class="rounded-full border border-[#C33939]/50 px-5 py-2 text-sm font-semibold text-[#C33939] transition hover:bg-[#FDEDEC]">Reject</button></div>'
                . '</form></div>';
            ?>
            <?= view('components/dialog', ['id' => "review-{$id}", 'title' => $student . ', ' . $course, 'body' => $body, 'size' => 'lg'], ['saveData' => false]) ?>
        <?php endforeach; ?>
    <?php endif; ?>

</div>

</div>

<?= $this->endSection() ?>
