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

$ctl   = 'w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm focus:border-brand/60 focus:outline-none focus:ring-2 focus:ring-brand/40';
$label = 'flex flex-col gap-1 text-xs font-medium text-gray-600';

// Quick date ranges: plain links, so they work without a script and can be shared.
$ranges = [
    'Any time'     => ['from' => null, 'to' => null],
    'Today'        => ['from' => date('Y-m-d'), 'to' => null],
    'Last 7 days'  => ['from' => date('Y-m-d', strtotime('-6 days')), 'to' => null],
    'Last 30 days' => ['from' => date('Y-m-d', strtotime('-29 days')), 'to' => null],
];
$rangeActive = static fn (array $r): bool => $filters->from === $r['from'] && $filters->to === $r['to'];
$customDates = ($filters->from !== null || $filters->to !== null) && array_filter($ranges, $rangeActive) === [];

// The narrower filters sit behind "More filters", with a count so none hides.
$moreCount = count(array_filter([$customDates, $filters->actor, $filters->targetType, $filters->institutionId]));

// What is filtering the list right now, each removable on its own.
$chips = [];
if ($filters->q !== null) {
    $chips['Search: ' . $filters->q] = 'q';
}
if ($filters->action !== null) {
    $chips['Action: ' . $filters->action] = 'action';
}
if ($filters->actor !== null) {
    $chips['Actor: ' . $filters->actor] = 'actor';
}
if ($filters->targetType !== null) {
    $chips['Target: ' . $filters->targetType] = 'target_type';
}
if ($filters->institutionId !== null) {
    $chips['Institution #' . $filters->institutionId] = 'institution';
}
if ($customDates) {
    $chips['Dates: ' . ($filters->from ?? 'start') . ' to ' . ($filters->to ?? 'now')] = ['from', 'to'];
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

<?php // From lg up the page fills the screen and only the table scrolls, on its own. ?>
<div class="flex flex-col rounded-3xl bg-white p-5 shadow-sm sm:p-8 lg:h-full lg:min-h-[28rem]" data-page-panel>

<?= view('components/page-head', ['title' => 'Audit log', 'icon' => 'tasks', 'actions' => '<button type="button" class="rounded-full border border-brand bg-white px-5 py-2 text-sm font-semibold text-brand transition hover:bg-brand-tint focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/50" data-dialog-open="audit-settings">Display settings</button> <a class="rounded-full bg-brand px-5 py-2 text-sm font-semibold text-white transition hover:bg-brand-dark focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/50" href="' . esc($exportUrl) . '">Export CSV</a>'], ['saveData' => false]) ?>

<?php if (session()->has('message')): ?>
    <div class="mt-4"><?= view('components/alert', ['tone' => 'success', 'text' => (string) session('message')], ['saveData' => false]) ?></div>
<?php endif; ?>
<?php if (session()->has('error')): ?>
    <div class="mt-4"><?= view('components/alert', ['tone' => 'danger', 'text' => (string) session('error')], ['saveData' => false]) ?></div>
<?php endif; ?>

<div class="mt-6 flex flex-col gap-4 lg:min-h-0 lg:flex-1">

    <form method="get" action="<?= site_url('admin/audit-log') ?>" role="search" class="flex flex-col gap-3 rounded-2xl border border-brand/20 bg-brand-tint p-4" data-toolbar data-audit-filters>
        <?php if ($filters->sort !== 'created_at' || $filters->dir !== 'desc'): ?>
            <input type="hidden" name="sort" value="<?= esc($filters->sort) ?>"><input type="hidden" name="dir" value="<?= esc($filters->dir) ?>">
        <?php endif; ?>

        <div class="flex flex-wrap items-end gap-3">
            <label class="<?= $label ?> min-w-[14rem] flex-1">Search
                <input class="<?= $ctl ?>" type="search" name="q" value="<?= esc($filters->q ?? '') ?>" placeholder="Actor, action, target, institution">
            </label>
            <label class="<?= $label ?> w-full sm:w-56">Action
                <select class="<?= $ctl ?>" name="action" data-auto-submit>
                    <?php foreach ($actionOptions as $optionValue => $optionLabel): ?>
                        <option value="<?= esc((string) $optionValue) ?>" <?= (string) ($filters->action ?? '') === (string) $optionValue ? 'selected' : '' ?>><?= esc((string) $optionLabel) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <button type="submit" class="rounded-full bg-brand px-5 py-2 text-sm font-semibold text-white transition hover:bg-brand-dark focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/50">Search</button>
        </div>

        <div class="flex flex-wrap items-center gap-2 text-sm">
            <span class="text-xs font-medium text-gray-600">When</span>
            <?php foreach ($ranges as $rangeLabel => $range): ?>
                <?php $rangeQuery = $query($range + ['page' => null]); ?>
                <a class="rounded-full border px-3 py-1 text-xs font-semibold transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/50 <?= $rangeActive($range) ? 'border-brand bg-brand text-white' : 'border-brand/30 bg-white text-brand hover:bg-brand-tint' ?>"
                   href="<?= site_url('admin/audit-log') . ($rangeQuery === '' ? '' : '?' . $rangeQuery) ?>" <?= $rangeActive($range) ? 'aria-current="true"' : '' ?>><?= esc($rangeLabel) ?></a>
            <?php endforeach; ?>
        </div>

        <details class="group" <?= $moreCount > 0 ? 'open' : '' ?>>
            <summary class="inline-flex cursor-pointer list-none items-center gap-2 rounded-full px-1 py-1 text-sm font-semibold text-brand hover:underline [&::-webkit-details-marker]:hidden">
                More filters
                <?php if ($moreCount > 0): ?><span class="rounded-full bg-brand px-2 text-xs text-white"><?= $moreCount ?></span><?php endif; ?>
                <svg class="h-4 w-4 transition group-open:rotate-180" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m5 8 5 5 5-5"/></svg>
            </summary>
            <div class="mt-3 grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
                <label class="<?= $label ?>">From <input class="<?= $ctl ?>" type="date" name="from" value="<?= esc($filters->from ?? '') ?>"></label>
                <label class="<?= $label ?>">To <input class="<?= $ctl ?>" type="date" name="to" value="<?= esc($filters->to ?? '') ?>"></label>
                <label class="<?= $label ?>">Actor (email or user id) <input class="<?= $ctl ?>" type="search" name="actor" value="<?= esc($filters->actor ?? '') ?>"></label>
                <label class="<?= $label ?>">Target type <input class="<?= $ctl ?>" type="search" name="target_type" value="<?= esc($filters->targetType ?? '') ?>" placeholder="e.g. user"></label>
                <label class="<?= $label ?>">Institution id <input class="<?= $ctl ?>" type="search" name="institution" inputmode="numeric" value="<?= esc((string) ($filters->institutionId ?? '')) ?>"></label>
            </div>
        </details>
    </form>

    <?php if ($filters->isFiltered()): ?>
        <div class="flex flex-wrap items-center gap-2 text-xs">
            <?php foreach ($chips as $chipLabel => $keys): ?>
                <?php $dropQuery = $query(array_fill_keys((array) $keys, null) + ['page' => null]); ?>
                <a class="inline-flex items-center gap-1 rounded-full bg-brand-tint px-3 py-1 font-semibold text-brand hover:bg-brand/10" href="<?= site_url('admin/audit-log') . ($dropQuery === '' ? '' : '?' . $dropQuery) ?>" aria-label="Remove filter <?= esc($chipLabel, 'attr') ?>"><?= esc($chipLabel) ?> <span aria-hidden="true">�</span></a>
            <?php endforeach; ?>
            <a class="px-2 py-1 text-sm font-semibold text-brand hover:underline" href="<?= site_url('admin/audit-log') ?>">Clear</a>
        </div>
    <?php endif; ?>

    <p class="text-sm text-[#646464]" aria-live="polite">
        <?= esc(number_format($total)) ?> <?= $total === 1 ? 'entry' : 'entries' ?><?= $filters->isFiltered() ? ' match' : '' ?>
    </p>

    <?= view('components/data-table', [
        'caption'    => 'Audit log',
        'columns'    => $columns,
        'rows'       => $rows,
        'fill'       => true,
        'sort'       => $filters->sort,
        'dir'        => $filters->dir,
        'sortUrl'    => $sortUrl,
        'emptyIcon'  => 'tasks',
        'emptyTitle' => $filters->isFiltered() ? 'No entries match these filters' : 'No entries yet',
        'emptyText'  => $filters->isFiltered() ? 'Try a wider date range or a different search.' : 'What happens on the platform is recorded here, newest first.',
    ], ['saveData' => false]) ?>

    <?= view('components/pagination', ['page' => $filters->page, 'pages' => $pages, 'total' => $total, 'baseUrl' => site_url('admin/audit-log'), 'query' => $filters->toQuery(['page' => null])], ['saveData' => false]) ?>

</div>

<?php
$settingsForm = '<form data-dialog-form method="post" action="' . site_url('admin/audit-log/settings') . '" class="flex flex-col gap-5">' . csrf_field()
    . '<input type="hidden" name="show_ip" value="0">'
    . '<label class="flex items-start gap-3 text-sm text-[#3D3D3D]">'
    . '<input type="checkbox" name="show_ip" value="1" class="mt-0.5 h-4 w-4 shrink-0 rounded border-gray-300 text-brand focus:ring-brand/40"' . ($showIp ? ' checked' : '') . '>'
    . '<span>Show the source IP of each entry<span class="mt-1 block text-xs text-[#646464]">Addresses are always recorded; this only controls whether they are shown and exported.</span></span>'
    . '</label>'
    . '<div class="flex flex-wrap items-center justify-end gap-3">'
    . '<button type="button" data-dialog-close class="rounded-full border border-brand bg-white px-5 py-2 text-sm font-semibold text-brand transition hover:bg-brand-tint focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/50">Cancel</button>'
    . '<button type="submit" class="rounded-full bg-brand px-5 py-2 text-sm font-semibold text-white transition hover:bg-brand-dark focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/50">Save</button>'
    . '</div></form>';
?>

<?= view('components/dialog', ['id' => 'audit-settings', 'title' => 'Display settings', 'body' => $settingsForm], ['saveData' => false]) ?>

</div>

<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script>
    // A choice in the Action list applies at once; the text boxes apply on Enter or Search.
    document.querySelectorAll('[data-audit-filters] [data-auto-submit]').forEach(function (el) {
        el.addEventListener('change', function () { el.form.requestSubmit(); });
    });
</script>
<?= $this->endSection() ?>
