<?php
/**
 * @var list<array<string, mixed>>           $backupRows
 * @var array{kind: string, message: string}|null $backupAlert
 * @var string                               $backupFolder
 */
$th    = 'px-3 py-2 text-left text-xs font-semibold uppercase tracking-wide text-gray-600';
$badge = [
    'requested' => 'bg-gray-100 text-gray-700',
    'running'   => 'bg-blue-100 text-blue-800',
    'succeeded' => 'bg-green-100 text-green-800',
    'failed'    => 'bg-red-100 text-red-800',
];
$busy = array_filter($backupRows, static fn (array $row): bool => in_array($row['status'], ['requested', 'running'], true)) !== [];
?>

<?= $this->extend('layouts/admin') ?>

<?= $this->section('content') ?>

<div class="flex flex-wrap items-center justify-between gap-3">
    <h1 class="text-2xl font-bold text-brand">Backups</h1>
    <form method="post" action="<?= site_url('admin/backups') ?>">
        <?= csrf_field() ?>
        <button type="submit" class="rounded bg-brand px-4 py-2 text-sm font-semibold text-white hover:bg-brand-dark disabled:opacity-50" <?= $busy ? 'disabled' : '' ?>>Back up now</button>
    </form>
</div>
<p class="mt-1 text-sm text-gray-600">The server takes the backups (<span class="font-mono">php spark backup:run</span>); this page shows what it did. Restoring is a manual procedure: see <span class="font-mono">docs/backup.md</span>.</p>

<?php if ($backupAlert !== null): ?>
    <p class="mt-4 rounded bg-red-50 px-3 py-2 text-sm text-red-700" role="alert" data-backup-alert="<?= esc($backupAlert['kind']) ?>"><?= esc($backupAlert['message']) ?></p>
<?php endif; ?>
<?php if (session()->has('message')): ?>
    <p class="mt-4 rounded bg-green-50 px-3 py-2 text-sm text-green-700" role="status"><?= esc((string) session('message')) ?></p>
<?php endif; ?>
<?php if (session()->has('error')): ?>
    <p class="mt-4 rounded bg-red-50 px-3 py-2 text-sm text-red-700" role="alert"><?= esc((string) session('error')) ?></p>
<?php endif; ?>

<div class="mt-6 overflow-x-auto rounded-lg bg-white shadow">
    <table class="min-w-full divide-y divide-gray-200 text-sm">
        <thead class="bg-gray-50">
            <tr>
                <th class="<?= $th ?>">Asked</th>
                <th class="<?= $th ?>">Trigger</th>
                <th class="<?= $th ?>">Status</th>
                <th class="<?= $th ?>">File</th>
                <th class="<?= $th ?>">Size</th>
                <th class="<?= $th ?>">Finished</th>
                <th class="<?= $th ?>">By</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-gray-100">
            <?php if ($backupRows === []): ?>
                <tr><td class="px-3 py-8 text-center text-gray-500" colspan="7">No backups recorded yet.</td></tr>
            <?php endif; ?>
            <?php foreach ($backupRows as $row): ?>
                <tr data-backup="<?= esc($row['status']) ?>">
                    <td class="whitespace-nowrap px-3 py-2 text-gray-600"><?= esc(substr((string) $row['created_at'], 0, 16)) ?></td>
                    <td class="px-3 py-2"><?= esc($row['trigger']) ?></td>
                    <td class="px-3 py-2">
                        <span class="rounded-full px-2 py-0.5 text-xs font-semibold <?= $badge[$row['status']] ?>"><?= esc($row['status']) ?></span>
                        <?php if ($row['message'] !== null): ?><span class="block text-xs text-red-700"><?= esc($row['message']) ?></span><?php endif; ?>
                    </td>
                    <td class="px-3 py-2 font-mono text-xs"><?= esc((string) ($row['file_name'] ?? '—')) ?></td>
                    <td class="whitespace-nowrap px-3 py-2"><?= $row['size_bytes'] === null ? '—' : esc(number_format((int) $row['size_bytes'] / 1048576, 1)) . ' MB' ?></td>
                    <td class="whitespace-nowrap px-3 py-2 text-gray-600"><?= esc(substr((string) ($row['finished_at'] ?? ''), 0, 16) ?: '—') ?></td>
                    <td class="px-3 py-2 text-xs text-gray-600"><?= esc((string) ($row['requested_by_label'] ?? 'system')) ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<?= $this->endSection() ?>
