<?php
/**
 * Audit log list. Filters live in the address (GET), so a filtered view can
 * be bookmarked or shared and every sort/paging/export link keeps it.
 *
 * @var App\Services\Audit\AuditLogFilters $filters
 */
$url = static function (string $path, array $query): string {
    $string = http_build_query($query);

    return site_url($path) . ($string === '' ? '' : '?' . $string);
};

$link = static fn (array $override = []): string => $url('admin/audit-log', $filters->toQuery($override));

$sortLink = static function (string $column) use ($filters, $link): string {
    $next = $filters->sort === $column && $filters->dir === 'asc' ? 'desc' : 'asc';

    return $link(['sort' => $column, 'dir' => $next, 'page' => null]);
};

$arrow = static fn (string $column): string => $filters->sort === $column ? ($filters->dir === 'asc' ? ' ▲' : ' ▼') : '';

$exportUrl = $url('admin/audit-log/export', $filters->toQuery(['page' => null]));

$input = 'rounded border border-gray-300 bg-white px-2 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-[#0040A6]/55';
$th    = 'px-3 py-2 text-left text-xs font-semibold uppercase tracking-wide text-gray-600';
?>

<?= $this->extend('layouts/admin') ?>

<?= $this->section('content') ?>

<div class="flex flex-wrap items-center justify-between gap-3">
    <h1 class="text-2xl font-bold text-[#0040A6]">Audit log</h1>
    <a class="rounded bg-[#0040A6] px-4 py-2 text-sm font-semibold text-white hover:bg-[#002068]" href="<?= esc($exportUrl) ?>">Export CSV</a>
</div>

<?php if (session()->has('message')): ?>
    <p class="mt-4 rounded bg-green-50 px-3 py-2 text-sm text-green-700" role="status"><?= esc((string) session('message')) ?></p>
<?php endif; ?>
<?php if (session()->has('error')): ?>
    <p class="mt-4 rounded bg-red-50 px-3 py-2 text-sm text-red-700" role="alert"><?= esc((string) session('error')) ?></p>
<?php endif; ?>

<form method="get" action="<?= site_url('admin/audit-log') ?>" class="mt-6 grid gap-3 rounded-lg bg-white p-4 shadow sm:grid-cols-2 lg:grid-cols-4">
    <label class="flex flex-col gap-1 text-xs font-medium text-gray-600">Search
        <input class="<?= $input ?>" type="search" name="q" value="<?= esc($filters->q ?? '') ?>" placeholder="Actor, action, target, institution">
    </label>
    <label class="flex flex-col gap-1 text-xs font-medium text-gray-600">From
        <input class="<?= $input ?>" type="date" name="from" value="<?= esc($filters->from ?? '') ?>">
    </label>
    <label class="flex flex-col gap-1 text-xs font-medium text-gray-600">To
        <input class="<?= $input ?>" type="date" name="to" value="<?= esc($filters->to ?? '') ?>">
    </label>
    <label class="flex flex-col gap-1 text-xs font-medium text-gray-600">Actor (email or user id)
        <input class="<?= $input ?>" type="text" name="actor" value="<?= esc($filters->actor ?? '') ?>">
    </label>
    <label class="flex flex-col gap-1 text-xs font-medium text-gray-600">Action
        <input class="<?= $input ?>" type="text" name="action" list="audit-actions" value="<?= esc($filters->action ?? '') ?>" placeholder="e.g. auth.login">
        <datalist id="audit-actions">
            <?php foreach ($actionNames as $actionName): ?>
                <option value="<?= esc($actionName) ?>"></option>
            <?php endforeach; ?>
        </datalist>
    </label>
    <label class="flex flex-col gap-1 text-xs font-medium text-gray-600">Target type
        <input class="<?= $input ?>" type="text" name="target_type" value="<?= esc($filters->targetType ?? '') ?>" placeholder="e.g. user">
    </label>
    <label class="flex flex-col gap-1 text-xs font-medium text-gray-600">Institution id
        <input class="<?= $input ?>" type="number" min="1" name="institution" value="<?= esc((string) ($filters->institutionId ?? '')) ?>">
    </label>
    <div class="flex items-end gap-2">
        <button type="submit" class="rounded bg-[#0040A6] px-4 py-1.5 text-sm font-semibold text-white hover:bg-[#002068]">Apply</button>
        <?php if ($filters->isFiltered()): ?>
            <a class="px-2 py-1.5 text-sm font-semibold text-[#0040A6] hover:underline" href="<?= site_url('admin/audit-log') ?>">Clear</a>
        <?php endif; ?>
    </div>
</form>

<p class="mt-4 text-sm text-gray-600" aria-live="polite">
    <?= esc(number_format($total)) ?> <?= $total === 1 ? 'entry' : 'entries' ?><?= $filters->isFiltered() ? ' match' : '' ?>
</p>

<div class="mt-2 overflow-x-auto rounded-lg bg-white shadow">
    <table class="min-w-full divide-y divide-gray-200 text-sm">
        <thead class="bg-gray-50">
            <tr>
                <th class="<?= $th ?>"><a href="<?= esc($sortLink('created_at')) ?>">Time<?= $arrow('created_at') ?></a></th>
                <th class="<?= $th ?>"><a href="<?= esc($sortLink('actor_label')) ?>">Actor<?= $arrow('actor_label') ?></a></th>
                <th class="<?= $th ?>"><a href="<?= esc($sortLink('acting_role')) ?>">Role<?= $arrow('acting_role') ?></a></th>
                <th class="<?= $th ?>"><a href="<?= esc($sortLink('action')) ?>">Action<?= $arrow('action') ?></a></th>
                <th class="<?= $th ?>">Target</th>
                <th class="<?= $th ?>"><a href="<?= esc($sortLink('institution_id')) ?>">Institution<?= $arrow('institution_id') ?></a></th>
                <?php if ($showIp): ?><th class="<?= $th ?>">Source IP</th><?php endif; ?>
                <th class="<?= $th ?>">Changes</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-gray-100">
            <?php if ($rows === []): ?>
                <tr><td class="px-3 py-8 text-center text-gray-500" colspan="<?= $showIp ? 8 : 7 ?>">No entries<?= $filters->isFiltered() ? ' match these filters' : ' yet' ?>.</td></tr>
            <?php endif; ?>
            <?php foreach ($rows as $row): ?>
                <tr>
                    <td class="whitespace-nowrap px-3 py-2 text-gray-700"><?= esc($row['created_at']) ?></td>
                    <td class="px-3 py-2">
                        <?= esc($row['actor_label'] ?? 'system') ?>
                        <?php if ($row['impersonator_user_id'] !== null): ?>
                            <span class="block text-xs text-amber-700">via admin #<?= esc((string) $row['impersonator_user_id']) ?></span>
                        <?php endif; ?>
                    </td>
                    <td class="px-3 py-2"><?= esc($row['acting_role'] ?? '—') ?></td>
                    <td class="px-3 py-2 font-mono text-xs"><?= esc($row['action']) ?></td>
                    <td class="px-3 py-2"><?= esc(trim(($row['target_type'] ?? '') . ' ' . ($row['target_id'] ?? '')) ?: '—') ?></td>
                    <td class="px-3 py-2"><?= esc($row['institution_name'] ?? ($row['institution_id'] !== null ? '#' . $row['institution_id'] : '—')) ?></td>
                    <?php if ($showIp): ?><td class="px-3 py-2 font-mono text-xs"><?= esc($row['ip_address'] ?? '—') ?></td><?php endif; ?>
                    <td class="px-3 py-2">
                        <?php if ($row['old_values'] !== null || $row['new_values'] !== null): ?>
                            <details>
                                <summary class="cursor-pointer text-[#0040A6]">View</summary>
                                <?php if ($row['old_values'] !== null): ?><pre class="mt-1 whitespace-pre-wrap text-xs text-gray-600">Before: <?= esc($row['old_values']) ?></pre><?php endif; ?>
                                <?php if ($row['new_values'] !== null): ?><pre class="mt-1 whitespace-pre-wrap text-xs text-gray-600">After: <?= esc($row['new_values']) ?></pre><?php endif; ?>
                            </details>
                        <?php else: ?>—<?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<nav class="mt-4 flex items-center justify-between text-sm" aria-label="Pages">
    <span class="text-gray-600">Page <?= esc((string) $filters->page) ?> of <?= esc((string) $pages) ?></span>
    <span class="flex gap-3">
        <?php if ($filters->page > 1): ?>
            <a class="font-semibold text-[#0040A6] hover:underline" href="<?= esc($link(['page' => $filters->page - 1])) ?>">Previous</a>
        <?php endif; ?>
        <?php if ($filters->page < $pages): ?>
            <a class="font-semibold text-[#0040A6] hover:underline" href="<?= esc($link(['page' => $filters->page + 1])) ?>">Next</a>
        <?php endif; ?>
    </span>
</nav>

<form method="post" action="<?= site_url('admin/audit-log/settings') ?>" class="mt-8 flex flex-wrap items-center gap-3 rounded-lg bg-white p-4 shadow">
    <?= csrf_field() ?>
    <input type="hidden" name="show_ip" value="0">
    <label class="flex items-center gap-2 text-sm text-gray-700">
        <input type="checkbox" name="show_ip" value="1" <?= $showIp ? 'checked' : '' ?>>
        Show the source IP of each entry (addresses are always recorded; this only controls whether they are shown and exported)
    </label>
    <button type="submit" class="rounded border border-[#0040A6] px-3 py-1.5 text-sm font-semibold text-[#0040A6] hover:bg-[#0040A6]/10">Save</button>
</form>

<?= $this->endSection() ?>
