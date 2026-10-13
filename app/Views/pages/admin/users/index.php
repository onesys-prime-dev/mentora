<?php
/**
 * People across every institution. Filters live in the address (GET), like the
 * audit log; ticking people enables the bulk buttons (resources/js/pages/admin-users).
 *
 * @var App\Services\Users\UserFilters $userFilters
 * @var list<array<string, mixed>>     $userRows
 * @var list<array<string, mixed>>     $userInstitutions
 * @var int                            $userSelf         the signed-in administrator's id
 */
use App\Services\Users\UserFilters;

$f = $userFilters;

$query = static fn (array $override = []): string => http_build_query($f->toQuery($override));
$link  = static fn (array $override = []): string => site_url('admin/users') . ($query($override) === '' ? '' : '?' . $query($override));

$sortUrl = static fn (string $column, string $next): string => $link(['sort' => $column, 'dir' => $next, 'page' => null]);

$exportUrl = site_url('admin/users/export') . ($query(['page' => null]) === '' ? '' : '?' . $query(['page' => null]));

$primary = 'rounded-full bg-brand px-5 py-2 text-sm font-semibold text-white transition hover:bg-brand-dark focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/50';
$second  = 'rounded-full border border-brand bg-white px-5 py-2 text-sm font-semibold text-brand transition hover:bg-brand-tint focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/50';
$warn    = 'rounded-full border border-[#946200] bg-white px-5 py-2 text-sm font-semibold text-[#946200] transition hover:bg-[#FFF1D6] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#946200]/50';

$institutionOptions = ['' => 'Any institution'];

foreach ($userInstitutions as $institution) {
    $institutionOptions[(string) $institution['id']] = (string) $institution['name'];
}

$roleOptions = ['' => 'Any role'] + UserFilters::ROLE_LABELS;

$rows = [];

foreach ($userRows as $row) {
    $name        = trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? ''));
    $shown       = $name !== '' ? $name : (string) $row['username'];
    $deactivated = (int) $row['active'] === 0 || $row['deactivated_at'] !== null;

    $nameCell = '<span data-user="' . esc((string) $row['id']) . '" data-status="' . ($deactivated ? 'deactivated' : 'active') . '">'
        . '<a class="font-medium text-brand hover:underline" href="' . site_url('admin/users/' . $row['id']) . '">' . esc($shown) . '</a>'
        . ((int) $row['id'] === $userSelf ? ' <span class="text-xs font-normal text-[#646464]">(you)</span>' : '')
        . '</span>';

    $rows[] = [
        'id'    => (string) $row['id'],
        'label' => $shown,
        'cells' => [
            'name'        => ['html' => $nameCell],
            'email'       => (string) ($row['email'] ?? '—'),
            'roles'       => implode(', ', array_map(static fn (string $role): string => UserFilters::ROLE_LABELS[$role] ?? $role, $row['roles'])),
            'institution' => $row['institutions'] === [] ? '—' : implode(', ', array_column($row['institutions'], 'name')),
            'status'      => ['html' => view('components/status-badge', ['label' => $deactivated ? 'Deactivated' : 'Active', 'tone' => $deactivated ? 'danger' : 'success'], ['saveData' => false])],
            'last_active' => substr((string) ($row['last_active'] ?? ''), 0, 16) ?: '—',
        ],
    ];
}

// One form: its buttons all act on whoever is ticked (the shared table adds their ids on submit).
$bulk = '<form method="post" action="' . site_url('admin/users/bulk') . '" data-bulk-form class="flex flex-wrap items-center gap-3">' . csrf_field()
    . '<button type="submit" name="action" value="deactivate" class="' . $warn . '" data-bulk-action>Deactivate</button>'
    . '<button type="submit" name="action" value="reactivate" class="' . $second . '" data-bulk-action>Reactivate</button>'
    . '<button type="submit" formaction="' . site_url('admin/users/export') . '" class="' . $second . '" data-bulk-action>Export selected (CSV)</button>'
    . '</form>';
?>

<?= $this->extend('layouts/admin') ?>

<?= $this->section('content') ?>

<?= view('components/page-head', [
    'title'   => 'Users',
    'icon'    => 'user',
    'actions' => '<a class="' . $second . '" href="' . esc($exportUrl) . '" data-export-filtered>Export these (CSV)</a>'
        . '<a class="' . $primary . '" href="' . site_url('admin/users/new') . '">Invite a person</a>',
], ['saveData' => false]) ?>

<?php if (session()->has('message')): ?>
    <div class="mt-4"><?= view('components/alert', ['tone' => 'success', 'text' => (string) session('message')], ['saveData' => false]) ?></div>
<?php endif; ?>
<?php if (session()->has('error')): ?>
    <div class="mt-4"><?= view('components/alert', ['tone' => 'danger', 'text' => (string) session('error')], ['saveData' => false]) ?></div>
<?php endif; ?>

<div class="mt-6 flex flex-col gap-4">

    <?= view('components/toolbar', [
        'action'   => site_url('admin/users'),
        'filtered' => $f->isFiltered(),
        'fields'   => [
            ['type' => 'search', 'name' => 'q', 'label' => 'Search', 'placeholder' => 'Name, email or username', 'value' => $f->q ?? '', 'span' => 'lg:col-span-1'],
            ['type' => 'select', 'name' => 'institution', 'label' => 'Institution', 'options' => $institutionOptions, 'value' => $f->institutionId === null ? '' : (string) $f->institutionId],
            ['type' => 'select', 'name' => 'role', 'label' => 'Role', 'options' => $roleOptions, 'value' => $f->role ?? ''],
            ['type' => 'select', 'name' => 'status', 'label' => 'Status', 'options' => ['' => 'Any status', 'active' => 'Active', 'deactivated' => 'Deactivated'], 'value' => $f->status ?? ''],
        ],
    ], ['saveData' => false]) ?>

    <p class="text-sm text-[#646464]" aria-live="polite">
        <?= esc(number_format($userTotal)) ?> <?= $userTotal === 1 ? 'person' : 'people' ?><?= $f->isFiltered() ? ' match' : '' ?>
    </p>

    <?= view('components/data-table', [
        'caption'    => 'People across every institution',
        'columns'    => [
            ['key' => 'name', 'label' => 'Name', 'sortable' => true],
            ['key' => 'email', 'label' => 'Email', 'sortable' => true],
            ['key' => 'roles', 'label' => 'Roles'],
            ['key' => 'institution', 'label' => 'Institutions'],
            ['key' => 'status', 'label' => 'Status'],
            ['key' => 'last_active', 'label' => 'Last active', 'sortable' => true],
        ],
        'rows'       => $rows,
        'sort'       => $f->sort,
        'dir'        => $f->dir,
        'sortUrl'    => $sortUrl,
        'selectable' => true,
        'bulk'       => $bulk,
        'nounOne'    => 'person',
        'nounMany'   => 'people',
        'emptyIcon'  => 'user',
        'emptyTitle' => $f->isFiltered() ? 'No one matches these filters' : 'No one yet',
        'emptyText'  => $f->isFiltered() ? 'Try a different search, institution, role or status.' : 'Invite the first person to get started.',
    ], ['saveData' => false]) ?>

    <?= view('components/pagination', ['page' => $f->page, 'pages' => $userPages, 'total' => $userTotal, 'baseUrl' => site_url('admin/users'), 'query' => $f->toQuery(['page' => null])], ['saveData' => false]) ?>

</div>

<?= $this->endSection() ?>
