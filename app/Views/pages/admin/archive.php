<?php
/**
 * The archive: past institutions and deleted institutions.
 *
 * @var list<array<string, mixed>> $pastRows
 * @var list<array<string, mixed>> $deletedRows each with purge_on, days_left and contents
 * @var int                        $retentionDays
 */
$btn   = 'rounded-full border px-3 py-1 text-xs font-semibold transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/50';
$input = 'w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm focus:border-brand/60 focus:outline-none focus:ring-2 focus:ring-brand/40';

$pastTable = [];

foreach ($pastRows as $row) {
    $pastTable[] = [
        'id'      => (string) $row['id'],
        'label'   => $row['name'],
        'cells'   => [
            'name'    => ['html' => '<span class="font-medium">' . esc($row['name']) . '</span>'],
            'code'    => ['html' => '<span class="font-mono text-xs">' . esc($row['code']) . '</span>'],
            'changed' => substr((string) $row['updated_at'], 0, 10),
        ],
        'actions' => '<a class="' . $btn . ' border-gray-300 bg-white text-[#3D3D3D] hover:bg-gray-50" href="' . site_url('admin/institutions/' . $row['id'] . '/edit') . '">Edit</a>',
    ];
}

$deletedTable = [];

foreach ($deletedRows as $row) {
    $c  = $row['contents'];
    $id = (string) $row['id'];
    $left = $row['days_left'] === 0 ? 'due now' : (string) $row['days_left'] . ' day' . ($row['days_left'] === 1 ? '' : 's') . ' left';

    $members = esc((string) $c['members']) . ' member account(s) are kept'
        . ($c['members_left_without_an_institution'] > 0 ? ', ' . esc((string) $c['members_left_without_an_institution']) . ' of them with no institution left' : '');

    $actions = '<div class="flex flex-wrap items-start justify-end gap-2 max-md:justify-start">'
        . '<form data-dialog-form method="post" action="' . site_url('admin/institutions/' . $id . '/restore') . '">' . csrf_field()
        . '<input type="hidden" name="return" value="archive">'
        . '<button type="submit" class="' . $btn . ' border-brand bg-white text-brand hover:bg-brand-tint">Restore</button></form>'
        . '<details class="text-left">'
        . '<summary class="' . $btn . ' cursor-pointer border-[#C33939] bg-white text-[#C33939] hover:bg-[#FDEDEC]">Purge now</summary>'
        . '<form data-dialog-form method="post" action="' . site_url('admin/institutions/' . $id . '/purge') . '" class="mt-2 flex w-72 flex-col gap-2">' . csrf_field()
        . '<input type="hidden" name="return" value="archive">'
        . '<p class="text-xs text-[#8E2424]">This cannot be undone. It permanently removes the institution and everything in it: '
        . esc((string) $c['departments']) . ' department(s), ' . esc((string) $c['programs']) . ' program(s), '
        . esc((string) $c['courses']) . ' course(s) and all their offerings, enrolments, work and grades. ' . $members . '.</p>'
        . '<label class="text-xs text-[#646464]" for="purge-' . $id . '">Type <strong>' . esc($row['name']) . '</strong> to confirm</label>'
        . '<input id="purge-' . $id . '" class="' . $input . '" type="text" name="confirm_name" autocomplete="off" required>'
        . '<button type="submit" class="' . $btn . ' border-[#C33939] bg-[#C33939] text-white hover:bg-[#8E2424]">Purge permanently</button>'
        . '</form></details></div>';

    $deletedTable[] = [
        'id'      => $id,
        'label'   => $row['name'],
        'cells'   => [
            'name'     => ['html' => '<span class="font-medium">' . esc($row['name']) . '</span>'],
            'code'     => ['html' => '<span class="font-mono text-xs">' . esc($row['code']) . '</span>'],
            'deleted'  => substr((string) $row['deleted_at'], 0, 10),
            'purge_on' => ['html' => esc($row['purge_on']) . '<span class="block text-xs ' . ($row['days_left'] === 0 ? 'font-semibold text-[#C33939]' : 'text-[#646464]') . '">' . esc($left) . '</span>'],
        ],
        'actions' => $actions,
    ];
}
?>

<?= $this->extend('layouts/admin') ?>

<?= $this->section('content') ?>

<div class="rounded-3xl bg-white p-5 shadow-sm sm:p-8" data-page-panel>

<?= view('components/page-head', ['title' => 'Archive', 'icon' => 'drafts'], ['saveData' => false]) ?>
<p class="mt-2 max-w-3xl text-sm text-[#646464]">
    Institutions that are no longer running. Past institutions keep all their data, read-only, until they are renewed.
    A deleted institution can be restored for <?= esc((string) $retentionDays) ?> days; after that it is purged for good.
</p>

<?php if (session()->has('message')): ?>
    <div class="mt-4"><?= view('components/alert', ['tone' => 'success', 'text' => (string) session('message')], ['saveData' => false]) ?></div>
<?php endif; ?>
<?php if (session()->has('error')): ?>
    <div class="mt-4"><?= view('components/alert', ['tone' => 'danger', 'text' => (string) session('error')], ['saveData' => false]) ?></div>
<?php endif; ?>

<section class="mt-8 flex flex-col gap-3" aria-labelledby="archive-past" data-section="past">
    <h2 id="archive-past" class="text-lg font-bold text-brand">Past institutions</h2>

    <?= view('components/data-table', [
        'caption'    => 'Past institutions',
        'columns'    => [
            ['key' => 'name', 'label' => 'Name'],
            ['key' => 'code', 'label' => 'Code'],
            ['key' => 'changed', 'label' => 'Last changed'],
        ],
        'rows'       => $pastTable,
        'emptyIcon'  => 'drafts',
        'emptyTitle' => 'No past institutions',
        'emptyText'  => 'An institution appears here when its subscription has ended and it has gone past its read-only period.',
    ], ['saveData' => false]) ?>
</section>

<section class="mt-8 flex flex-col gap-3" aria-labelledby="archive-deleted" data-section="deleted">
    <h2 id="archive-deleted" class="text-lg font-bold text-brand">Deleted institutions</h2>

    <?= view('components/data-table', [
        'caption'    => 'Deleted institutions',
        'columns'    => [
            ['key' => 'name', 'label' => 'Name'],
            ['key' => 'code', 'label' => 'Code'],
            ['key' => 'deleted', 'label' => 'Deleted'],
            ['key' => 'purge_on', 'label' => 'Purged on'],
        ],
        'rows'       => $deletedTable,
        'emptyIcon'  => 'drafts',
        'emptyTitle' => 'No deleted institutions',
        'emptyText'  => 'A deleted institution can be restored here until it is purged.',
    ], ['saveData' => false]) ?>
</section>

</div>

<?= $this->endSection() ?>
