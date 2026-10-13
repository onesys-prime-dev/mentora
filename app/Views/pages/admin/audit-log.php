<?php
/**
 * Audit log list. Filters live in the address (GET), so a filtered view can
 * be bookmarked or shared and every sort/paging/export link keeps it.
 *
 * @var App\Services\Audit\AuditLogFilters $filters
 */
$query = static fn (array $override = []): string => http_build_query($filters->toQuery($override));

$sortUrl = static fn (string $column, string $next): string => site_url('admin/audit-log') . '?' . $query(['sort' => $column, 'dir' => $next, 'page' => null]);

$exportUrl = site_url('admin/audit-log/export') . ($query(['page' => null]) === '' ? '' : '?' . $query(['page' => null]));

$actionOptions = ['' => 'Any action'];

foreach ($actionNames as $actionName) {
    $actionOptions[$actionName] = $actionName;
}

$auditRows = $rows;
$rows      = [];

foreach ($auditRows as $row) {
    $who = esc($row['actor_label'] ?? 'system')
        . ($row['impersonator_user_id'] !== null ? '<span class="block text-xs text-[#946200]">via admin #' . esc((string) $row['impersonator_user_id']) . '</span>' : '');

    $changes = '<span class="text-[#646464]">—</span>';

    if ($row['old_values'] !== null || $row['new_values'] !== null) {
        $changes = '<details><summary class="cursor-pointer text-brand hover:underline">View</summary>'
            . ($row['old_values'] !== null ? '<pre class="mt-1 whitespace-pre-wrap text-xs text-[#646464]">Before: ' . esc((string) $row['old_values']) . '</pre>' : '')
            . ($row['new_values'] !== null ? '<pre class="mt-1 whitespace-pre-wrap text-xs text-[#646464]">After: ' . esc((string) $row['new_values']) . '</pre>' : '')
            . '</details>';
    }

    $cells = [
        'created_at'     => (string) $row['created_at'],
        'actor_label'    => ['html' => $who],
        'acting_role'    => (string) ($row['acting_role'] ?? '—'),
        'action'         => ['html' => '<span class="font-mono text-xs">' . esc($row['action']) . '</span>'],
        'target'         => trim(((string) ($row['target_type'] ?? '')) . ' ' . ((string) ($row['target_id'] ?? ''))) ?: '—',
        'institution_id' => (string) ($row['institution_name'] ?? ($row['institution_id'] !== null ? '#' . $row['institution_id'] : '—')),
    ];

    if ($showIp) {
        $cells['ip'] = ['html' => '<span class="font-mono text-xs">' . esc((string) ($row['ip_address'] ?? '—')) . '</span>'];
    }

    $cells['changes'] = ['html' => $changes];

    $rows[] = ['id' => (string) $row['id'], 'label' => (string) $row['action'], 'cells' => $cells];
}

$columns = [
    ['key' => 'created_at', 'label' => 'Time', 'sortable' => true],
    ['key' => 'actor_label', 'label' => 'Actor', 'sortable' => true],
    ['key' => 'acting_role', 'label' => 'Role', 'sortable' => true],
    ['key' => 'action', 'label' => 'Action', 'sortable' => true],
    ['key' => 'target', 'label' => 'Target'],
    ['key' => 'institution_id', 'label' => 'Institution', 'sortable' => true],
];

if ($showIp) {
    $columns[] = ['key' => 'ip', 'label' => 'Source IP'];
}

$columns[] = ['key' => 'changes', 'label' => 'Changes'];
?>

<?= $this->extend('layouts/admin') ?>

<?= $this->section('content') ?>

<?= view('components/page-head', ['title' => 'Audit log', 'icon' => 'tasks', 'actions' => '<a class="rounded-full bg-brand px-5 py-2 text-sm font-semibold text-white transition hover:bg-brand-dark focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/50" href="' . esc($exportUrl) . '">Export CSV</a>'], ['saveData' => false]) ?>

<?php if (session()->has('message')): ?>
    <div class="mt-4"><?= view('components/alert', ['tone' => 'success', 'text' => (string) session('message')], ['saveData' => false]) ?></div>
<?php endif; ?>
<?php if (session()->has('error')): ?>
    <div class="mt-4"><?= view('components/alert', ['tone' => 'danger', 'text' => (string) session('error')], ['saveData' => false]) ?></div>
<?php endif; ?>

<div class="mt-6 flex flex-col gap-4">

    <?= view('components/toolbar', [
        'action'   => site_url('admin/audit-log'),
        'filtered' => $filters->isFiltered(),
        'fields'   => [
            ['type' => 'search', 'name' => 'q', 'label' => 'Search', 'placeholder' => 'Actor, action, target, institution', 'value' => $filters->q ?? '', 'span' => 'lg:col-span-2'],
            ['type' => 'date', 'name' => 'from', 'label' => 'From', 'value' => $filters->from ?? ''],
            ['type' => 'date', 'name' => 'to', 'label' => 'To', 'value' => $filters->to ?? ''],
            ['type' => 'search', 'name' => 'actor', 'label' => 'Actor (email or user id)', 'value' => $filters->actor ?? ''],
            ['type' => 'select', 'name' => 'action', 'label' => 'Action', 'options' => $actionOptions, 'value' => $filters->action ?? ''],
            ['type' => 'search', 'name' => 'target_type', 'label' => 'Target type', 'placeholder' => 'e.g. user', 'value' => $filters->targetType ?? ''],
            ['type' => 'search', 'name' => 'institution', 'label' => 'Institution id', 'value' => (string) ($filters->institutionId ?? ''), 'attrs' => ['inputmode' => 'numeric']],
        ],
    ], ['saveData' => false]) ?>

    <p class="text-sm text-[#646464]" aria-live="polite">
        <?= esc(number_format($total)) ?> <?= $total === 1 ? 'entry' : 'entries' ?><?= $filters->isFiltered() ? ' match' : '' ?>
    </p>

    <?= view('components/data-table', [
        'caption'    => 'Audit log',
        'columns'    => $columns,
        'rows'       => $rows,
        'sort'       => $filters->sort,
        'dir'        => $filters->dir,
        'sortUrl'    => $sortUrl,
        'emptyIcon'  => 'tasks',
        'emptyTitle' => $filters->isFiltered() ? 'No entries match these filters' : 'No entries yet',
        'emptyText'  => $filters->isFiltered() ? 'Try a wider date range or a different search.' : 'What happens on the platform is recorded here, newest first.',
    ], ['saveData' => false]) ?>

    <?= view('components/pagination', ['page' => $filters->page, 'pages' => $pages, 'total' => $total, 'baseUrl' => site_url('admin/audit-log'), 'query' => $filters->toQuery(['page' => null])], ['saveData' => false]) ?>

</div>

<form method="post" action="<?= site_url('admin/audit-log/settings') ?>" class="mt-8 flex flex-wrap items-center gap-3 rounded-2xl bg-white p-4 shadow-sm">
    <?= csrf_field() ?>
    <input type="hidden" name="show_ip" value="0">
    <label class="flex items-center gap-2 text-sm text-[#3D3D3D]">
        <input type="checkbox" name="show_ip" value="1" class="h-4 w-4 rounded border-gray-300 text-brand focus:ring-brand/40" <?= $showIp ? 'checked' : '' ?>>
        Show the source IP of each entry (addresses are always recorded; this only controls whether they are shown and exported)
    </label>
    <button type="submit" class="rounded-full border border-brand bg-white px-5 py-2 text-sm font-semibold text-brand transition hover:bg-brand-tint focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/50">Save</button>
</form>

<?= $this->endSection() ?>
