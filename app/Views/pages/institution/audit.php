<?php
/**
 * The institution's audit log. Filters, sort and page live in the address, so
 * a view can be shared and every link keeps it. No source addresses.
 *
 * @var App\Services\Audit\AuditLogFilters $auditFilters
 * @var list<array<string, mixed>>         $auditRows
 * @var int                                $auditTotal
 * @var int                                $auditPages
 * @var array<string, string>              $auditActions
 * @var array<string, string>              $auditTargets
 */
$f        = $auditFilters;
$base     = 'institution/audit';
$filtered = $f->isFiltered();
$query    = $f->toQuery(['page' => null]);

$sortUrl = static function (string $column) use ($f, $base): string {
    $next = $f->sort === $column && $f->dir === 'asc' ? 'desc' : 'asc';

    return site_url($base) . '?' . http_build_query($f->toQuery(['sort' => $column, 'dir' => $next, 'page' => null]));
};

$exportUrl = site_url($base . '/export') . ($query === [] ? '' : '?' . http_build_query($query));

$rows = [];

foreach ($auditRows as $row) {
    $details = '<span class="text-[#646464]">—</span>';

    if ($row['withheld']) {
        $details = '<span class="text-xs text-[#646464]">Kept by Mentora</span>';
    } elseif ($row['old_values'] !== null || $row['new_values'] !== null) {
        $details = '<details><summary class="cursor-pointer text-brand hover:underline">View</summary>'
            . ($row['old_values'] !== null ? '<pre class="mt-1 whitespace-pre-wrap text-xs text-[#646464]">Before: ' . esc((string) $row['old_values']) . '</pre>' : '')
            . ($row['new_values'] !== null ? '<pre class="mt-1 whitespace-pre-wrap text-xs text-[#646464]">After: ' . esc((string) $row['new_values']) . '</pre>' : '')
            . '</details>';
    }

    $who = esc((string) $row['actor']) . ($row['via'] !== null ? '<span class="block text-xs text-[#946200]">viewing as, by ' . esc((string) $row['via']) . '</span>' : '');

    $rows[] = [
        'id'    => $row['id'],
        'label' => $row['action_label'],
        'cells' => [
            'created_at'  => date('j M Y H:i', strtotime((string) $row['created_at'])),
            'actor_label' => ['html' => $who],
            'acting_role' => $row['acting_role'] === null ? '—' : ucfirst(str_replace('_', ' ', (string) $row['acting_role'])),
            'action'      => $row['action_label'],
            'target'      => trim(((string) ($row['target_type'] ?? '')) . ' ' . ((string) ($row['target_id'] ?? ''))) ?: '—',
            'details'     => ['html' => $details],
        ],
    ];
}
?>

<?= $this->extend('layouts/app') ?>

<?= $this->section('content') ?>

<div class="py-3">

<?php // The way back stays on the background, above the panel, not in the box. ?>
<?= view('components/back-link', ['backUrl' => site_url('institution/dashboard'), 'backLabel' => 'Back to home'], ['saveData' => false]) ?>

<div class="flex flex-col gap-6 rounded-3xl bg-white p-5 shadow-sm sm:p-8" data-page-panel>

    <?= view('components/page-head', [
        'title'       => 'Audit log',
        'icon'        => 'info',
        'description' => 'Who did what in your institution, and when. Entries are never changed or removed.',
        'actions'     => '<a class="rounded-full border border-brand bg-white px-5 py-2 text-sm font-semibold text-brand transition hover:bg-brand-tint focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/50" href="' . esc($exportUrl) . '" data-audit-export>Export CSV</a>',
    ], ['saveData' => false]) ?>

    <?= view('components/toolbar', [
        'surface'  => 'tinted', // on the page's white panel a white card would not stand out
        'action'   => site_url($base),
        'filtered' => $filtered,
        'fields'   => [
            ['type' => 'search', 'name' => 'q', 'label' => 'Search', 'placeholder' => 'Who, what or which record', 'value' => $f->q ?? '', 'span' => 'lg:col-span-2'],
            ['type' => 'date', 'name' => 'from', 'label' => 'From', 'value' => $f->from ?? ''],
            ['type' => 'date', 'name' => 'to', 'label' => 'To', 'value' => $f->to ?? ''],
            ['type' => 'search', 'name' => 'actor', 'label' => 'Who (email)', 'placeholder' => 'Part of an email', 'value' => $f->actor ?? ''],
            ['type' => 'select', 'name' => 'action', 'label' => 'What', 'options' => ['' => 'Anything'] + $auditActions, 'value' => $f->action ?? ''],
            ['type' => 'select', 'name' => 'target_type', 'label' => 'About', 'options' => ['' => 'Anything'] + $auditTargets, 'value' => $f->targetType ?? ''],
        ],
    ], ['saveData' => false]) ?>

    <p class="text-sm text-[#646464]" aria-live="polite" data-audit-total>
        <?= esc(number_format($auditTotal)) ?> <?= $auditTotal === 1 ? 'entry' : 'entries' ?><?= $filtered ? ' match' : '' ?>
    </p>

    <?= view('components/data-table', [
        'caption'    => 'Audit log',
        'columns'    => [
            ['key' => 'created_at', 'label' => 'When', 'sortable' => true],
            ['key' => 'actor_label', 'label' => 'Who', 'sortable' => true],
            ['key' => 'acting_role', 'label' => 'Role', 'sortable' => true],
            ['key' => 'action', 'label' => 'What', 'sortable' => true],
            ['key' => 'target', 'label' => 'About'],
            ['key' => 'details', 'label' => 'Changes'],
        ],
        'rows'       => $rows,
        'sort'       => $f->sort,
        'dir'        => $f->dir,
        'sortUrl'    => $sortUrl,
        'emptyIcon'  => 'info',
        'emptyTitle' => $filtered ? 'No entries match these filters' : 'Nothing recorded yet',
        'emptyText'  => $filtered ? 'Try a wider date range or a different search.' : 'What people do in your institution is listed here, newest first.',
    ], ['saveData' => false]) ?>

    <?= view('components/pagination', ['page' => $f->page, 'pages' => $auditPages, 'total' => $auditTotal, 'baseUrl' => site_url($base), 'query' => $f->toQuery(['page' => null])], ['saveData' => false]) ?>

</div>

</div>

<?= $this->endSection() ?>
