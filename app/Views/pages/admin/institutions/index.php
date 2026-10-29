<?php
/**
 * Institutions list. Filters live in the address (GET), like the audit log.
 *
 * @var App\Services\Institutions\InstitutionFilters $institutionFilters
 * @var list<array<string, mixed>>                   $institutionRows
 */
$f = $institutionFilters;

$link = static fn (array $override = []): string => site_url('admin/institutions') . ($f->toQuery($override) === [] ? '' : '?' . http_build_query($f->toQuery($override)));

$sortUrl = static fn (string $column, string $next): string => $link(['sort' => $column, 'dir' => $next, 'page' => null]);

// A status is a word and a tone, never colour alone (components/status-badge).
$statusLabels = [
    'pending'   => ['Pending', 'neutral'],
    'active'    => ['Active', 'success'],
    'read_only' => ['Read-only', 'warning'],
    'past'      => ['Past', 'danger'],
];

$btn   = 'rounded-full border px-3 py-1 text-xs font-semibold transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/50';
$plain = 'border-gray-300 bg-white text-[#3D3D3D] hover:bg-gray-50';
$brand = 'border-brand bg-white text-brand hover:bg-brand-tint';
$input = 'w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm focus:border-brand/60 focus:outline-none focus:ring-2 focus:ring-brand/40';

$statusOptions = ['' => 'All (not deleted)'];

foreach ([...array_keys($statusLabels), 'deleted'] as $status) {
    $statusOptions[$status] = $status === 'deleted' ? 'Deleted' : $statusLabels[$status][0];
}

$planOptions = ['' => 'Any plan'];

foreach ($institutionPlans as $plan) {
    $planOptions[(string) $plan['id']] = (string) $plan['name'];
}

$rows = [];

foreach ($institutionRows as $row) {
    $isDeleted = $row['deleted_at'] !== null;
    [$statusLabel, $statusTone] = $statusLabels[$row['status']] ?? [$row['status'], 'neutral'];
    $id = (string) $row['id'];

    $name = $isDeleted
        ? esc($row['name'])
        : '<a class="font-medium text-brand hover:underline" href="' . site_url('admin/institutions/' . $id) . '">' . esc($row['name']) . '</a>';

    $badges = view('components/status-badge', ['label' => $statusLabel, 'tone' => $statusTone], ['saveData' => false])
        . ($isDeleted ? ' ' . view('components/status-badge', ['label' => 'Deleted', 'tone' => 'neutral'], ['saveData' => false]) : '');

    $actions = '<div class="flex flex-wrap items-start justify-end gap-2 max-md:justify-start">';

    if ($isDeleted) {
        $actions .= '<form data-dialog-form method="post" action="' . site_url('admin/institutions/' . $id . '/restore') . '">' . csrf_field()
            . '<button type="submit" class="' . $btn . ' ' . $brand . '">Restore</button></form>';
    } else {
        if ($row['status'] === 'pending') {
            $actions .= '<a class="' . $btn . ' ' . $brand . '" href="' . site_url('admin/institutions/' . $id . '/onboarding') . '">Finish onboarding</a>';
        }

        foreach ([['Edit', 'edit'], ['Subscription', 'subscription'], ['Features', 'flags']] as [$label, $path]) {
            $actions .= '<a class="' . $btn . ' ' . $plain . '" href="' . site_url('admin/institutions/' . $id . '/' . $path) . '">' . $label . '</a>';
        }

        if (in_array($row['status'], ['active', 'read_only'], true)) {
            $actions .= '<form method="post" action="' . site_url('admin/institutions/' . $id . '/suspend') . '">' . csrf_field()
                . '<button type="submit" class="' . $btn . ' border-[#946200] bg-white text-[#946200] hover:bg-[#FFF1D6]">Suspend</button></form>';
        }

        if ($row['status'] !== 'active') {
            $actions .= '<details class="text-left">'
                . '<summary class="' . $btn . ' cursor-pointer border-[#C33939] bg-white text-[#C33939] hover:bg-[#FDEDEC]">Delete</summary>'
                . '<form method="post" action="' . site_url('admin/institutions/' . $id . '/delete') . '" class="mt-2 flex w-64 flex-col gap-2">' . csrf_field()
                . '<label class="text-xs text-[#646464]" for="confirm-' . $id . '">Type <strong>' . esc($row['name']) . '</strong> to confirm</label>'
                . '<input id="confirm-' . $id . '" class="' . $input . '" type="text" name="confirm_name" autocomplete="off" required>'
                . '<button type="submit" class="' . $btn . ' border-[#C33939] bg-[#C33939] text-white hover:bg-[#8E2424]">Delete institution</button>'
                . '</form></details>';
        }
    }

    $actions .= '</div>';

    $rows[] = [
        'id'      => $id,
        'label'   => $row['name'],
        'cells'   => [
            'name'       => ['html' => $name],
            'code'       => ['html' => '<span class="font-mono text-xs">' . esc($row['code']) . '</span>'],
            'status'     => ['html' => $badges],
            'plan'       => $row['plan_name'] ?? '—',
            'created_at' => substr((string) $row['created_at'], 0, 10),
        ],
        'actions' => $actions,
    ];
}
?>

<?= $this->extend('layouts/admin') ?>

<?= $this->section('content') ?>

<div class="rounded-3xl bg-white p-5 shadow-sm sm:p-8" data-page-panel>

<?= view('components/page-head', ['title' => 'Institutions', 'icon' => 'hat', 'actions' => '<a class="rounded-full bg-brand px-5 py-2 text-sm font-semibold text-white transition hover:bg-brand-dark focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/50" href="' . site_url('admin/institutions/new') . '">Add an institution</a>'], ['saveData' => false]) ?>

<?php if (session()->has('message')): ?>
    <div class="mt-4"><?= view('components/alert', ['tone' => 'success', 'text' => (string) session('message')], ['saveData' => false]) ?></div>
<?php endif; ?>
<?php if (session()->has('error')): ?>
    <div class="mt-4"><?= view('components/alert', ['tone' => 'danger', 'text' => (string) session('error')], ['saveData' => false]) ?></div>
<?php endif; ?>

<div class="mt-6 flex flex-col gap-4">

    <?= view('components/toolbar', [
        'surface'  => 'tinted', // on the page's white panel a white card would not stand out
        'action'   => site_url('admin/institutions'),
        'filtered' => $f->isFiltered(),
        'fields'   => [
            ['type' => 'search', 'name' => 'q', 'label' => 'Search', 'placeholder' => 'Name or code', 'value' => $f->q ?? '', 'span' => 'lg:col-span-2'],
            ['type' => 'select', 'name' => 'status', 'label' => 'Status', 'options' => $statusOptions, 'value' => $f->status ?? ''],
            ['type' => 'select', 'name' => 'plan', 'label' => 'Plan', 'options' => $planOptions, 'value' => $f->planId === null ? '' : (string) $f->planId],
        ],
    ], ['saveData' => false]) ?>

    <p class="text-sm text-[#646464]" aria-live="polite">
        <?= esc(number_format($institutionTotal)) ?> <?= $institutionTotal === 1 ? 'institution' : 'institutions' ?><?= $f->isFiltered() ? ' match' : '' ?>
    </p>

    <?= view('components/data-table', [
        'caption'    => 'Institutions',
        'columns'    => [
            ['key' => 'name', 'label' => 'Name', 'sortable' => true],
            ['key' => 'code', 'label' => 'Code', 'sortable' => true],
            ['key' => 'status', 'label' => 'Status', 'sortable' => true],
            ['key' => 'plan', 'label' => 'Plan'],
            ['key' => 'created_at', 'label' => 'Added', 'sortable' => true],
        ],
        'rows'       => $rows,
        'sort'       => $f->sort,
        'dir'        => $f->dir,
        'sortUrl'    => $sortUrl,
        'emptyIcon'  => 'hat',
        'emptyTitle' => $f->isFiltered() ? 'No institutions match these filters' : 'No institutions yet',
        'emptyText'  => $f->isFiltered() ? 'Try a different search, status or plan.' : 'Add the first institution to start onboarding a customer.',
    ], ['saveData' => false]) ?>

    <?= view('components/pagination', ['page' => $f->page, 'pages' => $institutionPages, 'total' => $institutionTotal, 'baseUrl' => site_url('admin/institutions'), 'query' => $f->toQuery(['page' => null])], ['saveData' => false]) ?>

</div>

</div>

<?= $this->endSection() ?>
