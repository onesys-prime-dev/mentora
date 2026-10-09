<?php
/**
 * Institutions list. Filters live in the address (GET), like the audit log.
 *
 * @var App\Services\Institutions\InstitutionFilters $institutionFilters
 * @var list<array<string, mixed>>                   $institutionRows
 */
$f = $institutionFilters;

$url = static function (string $path, array $query): string {
    $string = http_build_query($query);

    return site_url($path) . ($string === '' ? '' : '?' . $string);
};

$link = static fn (array $override = []): string => $url('admin/institutions', $f->toQuery($override));

$sortLink = static function (string $column) use ($f, $link): string {
    $next = $f->sort === $column && $f->dir === 'asc' ? 'desc' : 'asc';

    return $link(['sort' => $column, 'dir' => $next, 'page' => null]);
};

$arrow = static fn (string $column): string => $f->sort === $column ? ($f->dir === 'asc' ? ' ▲' : ' ▼') : '';

$statusLabels = [
    'pending'   => ['Pending', 'bg-gray-100 text-gray-700'],
    'active'    => ['Active', 'bg-green-100 text-green-800'],
    'read_only' => ['Read-only', 'bg-amber-100 text-amber-800'],
    'past'      => ['Past', 'bg-red-100 text-red-800'],
];

$input = 'rounded border border-gray-300 bg-white px-2 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-[#0040A6]/55';
$th    = 'px-3 py-2 text-left text-xs font-semibold uppercase tracking-wide text-gray-600';
$btn   = 'rounded border px-2.5 py-1 text-xs font-semibold';
?>

<?= $this->extend('layouts/admin') ?>

<?= $this->section('content') ?>

<div class="flex flex-wrap items-center justify-between gap-3">
    <h1 class="text-2xl font-bold text-[#0040A6]">Institutions</h1>
    <a class="rounded bg-[#0040A6] px-4 py-2 text-sm font-semibold text-white hover:bg-[#002068]" href="<?= site_url('admin/institutions/new') ?>">Add an institution</a>
</div>

<?php if (session()->has('message')): ?>
    <p class="mt-4 rounded bg-green-50 px-3 py-2 text-sm text-green-700" role="status"><?= esc((string) session('message')) ?></p>
<?php endif; ?>
<?php if (session()->has('error')): ?>
    <p class="mt-4 rounded bg-red-50 px-3 py-2 text-sm text-red-700" role="alert"><?= esc((string) session('error')) ?></p>
<?php endif; ?>

<form method="get" action="<?= site_url('admin/institutions') ?>" class="mt-6 flex flex-wrap items-end gap-3 rounded-lg bg-white p-4 shadow">
    <label class="flex flex-col gap-1 text-xs font-medium text-gray-600">Search
        <input class="<?= $input ?>" type="search" name="q" value="<?= esc($f->q ?? '') ?>" placeholder="Name or code">
    </label>
    <label class="flex flex-col gap-1 text-xs font-medium text-gray-600">Status
        <select class="<?= $input ?>" name="status">
            <option value="">All (not deleted)</option>
            <?php foreach ([...array_keys($statusLabels), 'deleted'] as $status): ?>
                <option value="<?= esc($status) ?>" <?= $f->status === $status ? 'selected' : '' ?>><?= esc($status === 'deleted' ? 'Deleted' : $statusLabels[$status][0]) ?></option>
            <?php endforeach; ?>
        </select>
    </label>
    <label class="flex flex-col gap-1 text-xs font-medium text-gray-600">Plan
        <select class="<?= $input ?>" name="plan">
            <option value="">Any plan</option>
            <?php foreach ($institutionPlans as $plan): ?>
                <option value="<?= esc((string) $plan['id']) ?>" <?= $f->planId === (int) $plan['id'] ? 'selected' : '' ?>><?= esc($plan['name']) ?></option>
            <?php endforeach; ?>
        </select>
    </label>
    <button type="submit" class="rounded bg-[#0040A6] px-4 py-1.5 text-sm font-semibold text-white hover:bg-[#002068]">Apply</button>
    <?php if ($f->isFiltered()): ?>
        <a class="px-2 py-1.5 text-sm font-semibold text-[#0040A6] hover:underline" href="<?= site_url('admin/institutions') ?>">Clear</a>
    <?php endif; ?>
</form>

<p class="mt-4 text-sm text-gray-600" aria-live="polite">
    <?= esc(number_format($institutionTotal)) ?> <?= $institutionTotal === 1 ? 'institution' : 'institutions' ?><?= $f->isFiltered() ? ' match' : '' ?>
</p>

<div class="mt-2 overflow-x-auto rounded-lg bg-white shadow">
    <table class="min-w-full divide-y divide-gray-200 text-sm">
        <thead class="bg-gray-50">
            <tr>
                <th class="<?= $th ?>"><a href="<?= esc($sortLink('name')) ?>">Name<?= $arrow('name') ?></a></th>
                <th class="<?= $th ?>"><a href="<?= esc($sortLink('code')) ?>">Code<?= $arrow('code') ?></a></th>
                <th class="<?= $th ?>"><a href="<?= esc($sortLink('status')) ?>">Status<?= $arrow('status') ?></a></th>
                <th class="<?= $th ?>">Plan</th>
                <th class="<?= $th ?>"><a href="<?= esc($sortLink('created_at')) ?>">Added<?= $arrow('created_at') ?></a></th>
                <th class="<?= $th ?>">Actions</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-gray-100">
            <?php if ($institutionRows === []): ?>
                <tr><td class="px-3 py-8 text-center text-gray-500" colspan="6">No institutions<?= $f->isFiltered() ? ' match these filters' : ' yet' ?>.</td></tr>
            <?php endif; ?>

            <?php foreach ($institutionRows as $row): ?>
                <?php
                $isDeleted = $row['deleted_at'] !== null;
                [$statusLabel, $statusClass] = $statusLabels[$row['status']] ?? [$row['status'], 'bg-gray-100 text-gray-700'];
                ?>
                <tr>
                    <td class="px-3 py-2 font-medium"><?= esc($row['name']) ?></td>
                    <td class="px-3 py-2 font-mono text-xs"><?= esc($row['code']) ?></td>
                    <td class="px-3 py-2">
                        <span class="rounded-full px-2 py-0.5 text-xs font-semibold <?= $statusClass ?>"><?= esc($statusLabel) ?></span>
                        <?php if ($isDeleted): ?><span class="ml-1 rounded-full bg-gray-800 px-2 py-0.5 text-xs font-semibold text-white">Deleted</span><?php endif; ?>
                    </td>
                    <td class="px-3 py-2"><?= esc($row['plan_name'] ?? '—') ?></td>
                    <td class="whitespace-nowrap px-3 py-2 text-gray-600"><?= esc(substr((string) $row['created_at'], 0, 10)) ?></td>
                    <td class="px-3 py-2">
                        <div class="flex flex-wrap items-start gap-2">
                            <?php if ($isDeleted): ?>
                                <form method="post" action="<?= site_url('admin/institutions/' . $row['id'] . '/restore') ?>">
                                    <?= csrf_field() ?>
                                    <button type="submit" class="<?= $btn ?> border-[#0040A6] text-[#0040A6] hover:bg-[#0040A6]/10">Restore</button>
                                </form>
                            <?php else: ?>
                                <a class="<?= $btn ?> border-gray-300 text-gray-700 hover:bg-gray-50" href="<?= site_url('admin/institutions/' . $row['id'] . '/edit') ?>">Edit</a>
                                <a class="<?= $btn ?> border-gray-300 text-gray-700 hover:bg-gray-50" href="<?= site_url('admin/institutions/' . $row['id'] . '/subscription') ?>">Subscription</a>

                                <?php if (in_array($row['status'], ['active', 'read_only'], true)): ?>
                                    <form method="post" action="<?= site_url('admin/institutions/' . $row['id'] . '/suspend') ?>">
                                        <?= csrf_field() ?>
                                        <button type="submit" class="<?= $btn ?> border-amber-500 text-amber-700 hover:bg-amber-50">Suspend</button>
                                    </form>
                                <?php endif; ?>

                                <?php if ($row['status'] !== 'active'): ?>
                                    <details>
                                        <summary class="<?= $btn ?> cursor-pointer border-red-400 text-red-700 hover:bg-red-50">Delete</summary>
                                        <form method="post" action="<?= site_url('admin/institutions/' . $row['id'] . '/delete') ?>" class="mt-2 flex flex-col gap-2">
                                            <?= csrf_field() ?>
                                            <label class="text-xs text-gray-600" for="confirm-<?= esc((string) $row['id']) ?>">Type <strong><?= esc($row['name']) ?></strong> to confirm</label>
                                            <input id="confirm-<?= esc((string) $row['id']) ?>" class="<?= $input ?>" type="text" name="confirm_name" autocomplete="off" required>
                                            <button type="submit" class="<?= $btn ?> border-red-600 bg-red-600 text-white hover:bg-red-700">Delete institution</button>
                                        </form>
                                    </details>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<nav class="mt-4 flex items-center justify-between text-sm" aria-label="Pages">
    <span class="text-gray-600">Page <?= esc((string) $f->page) ?> of <?= esc((string) $institutionPages) ?></span>
    <span class="flex gap-3">
        <?php if ($f->page > 1): ?>
            <a class="font-semibold text-[#0040A6] hover:underline" href="<?= esc($link(['page' => $f->page - 1])) ?>">Previous</a>
        <?php endif; ?>
        <?php if ($f->page < $institutionPages): ?>
            <a class="font-semibold text-[#0040A6] hover:underline" href="<?= esc($link(['page' => $f->page + 1])) ?>">Next</a>
        <?php endif; ?>
    </span>
</nav>

<?= $this->endSection() ?>
