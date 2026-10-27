<?php
/**
 * @var list<array<string, mixed>>           $backupRows
 * @var array{kind: string, message: string}|null $backupAlert
 * @var string                               $backupFolder
 */
// A status is a word and a tone, never colour alone (components/status-badge).
$tones = ['requested' => 'neutral', 'running' => 'info', 'succeeded' => 'success', 'failed' => 'danger'];

$busy = array_filter($backupRows, static fn (array $row): bool => in_array($row['status'], ['requested', 'running'], true)) !== [];

$backupTable = [];

foreach ($backupRows as $row) {
    $status = '<span data-backup="' . esc($row['status']) . '">'
        . view('components/status-badge', ['label' => ucfirst((string) $row['status']), 'tone' => $tones[$row['status']] ?? 'neutral'], ['saveData' => false])
        . '</span>'
        . ($row['message'] !== null ? '<span class="mt-1 block text-xs text-[#C33939]">' . esc($row['message']) . '</span>' : '');

    $backupTable[] = [
        'id'    => (string) $row['id'],
        'label' => (string) $row['created_at'],
        'cells' => [
            'asked'    => substr((string) $row['created_at'], 0, 16),
            'trigger'  => (string) $row['trigger'],
            'status'   => ['html' => $status],
            'file'     => ['html' => '<span class="font-mono text-xs">' . esc((string) ($row['file_name'] ?? '—')) . '</span>'],
            'size'     => $row['size_bytes'] === null ? '—' : number_format((int) $row['size_bytes'] / 1048576, 1) . ' MB',
            'finished' => substr((string) ($row['finished_at'] ?? ''), 0, 16) ?: '—',
            // The copy off the server (U3): only for a good backup, when an off-site command is set.
            'offsite'  => ['html' => match ($row['offsite_status'] ?? null) {
                'copied' => view('components/status-badge', ['label' => 'Copied', 'tone' => 'success'], ['saveData' => false]),
                'failed' => view('components/status-badge', ['label' => 'Failed', 'tone' => 'danger'], ['saveData' => false]) . '<span class="mt-1 block text-xs text-[#C33939]">' . esc((string) $row['offsite_message']) . '</span>',
                default  => '—',
            }],
            'by'       => (string) ($row['requested_by_label'] ?? 'system'),
        ],
    ];
}
?>

<?= $this->extend('layouts/admin') ?>

<?= $this->section('content') ?>

<?= view('components/page-head', ['title' => 'Backups', 'icon' => 'folder', 'actions' => '<form data-dialog-form method="post" action="' . site_url('admin/backups') . '">' . csrf_field() . '<button type="submit" class="rounded-full bg-brand px-5 py-2 text-sm font-semibold text-white transition hover:bg-brand-dark focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/50 disabled:cursor-not-allowed disabled:opacity-50" ' . ($busy ? 'disabled' : '') . '>Back up now</button></form>'], ['saveData' => false]) ?>
<p class="mt-1 text-sm text-[#646464]">The server takes the backups (<span class="font-mono">php spark backup:run</span>); this page shows what it did. Restoring is a manual procedure: see <span class="font-mono">docs/backup.md</span>.</p>

<?php if ($backupAlert !== null): ?>
    <div class="mt-4" data-backup-alert="<?= esc($backupAlert['kind']) ?>"><?= view('components/alert', ['tone' => 'danger', 'text' => $backupAlert['message']], ['saveData' => false]) ?></div>
<?php endif; ?>
<?php if (session()->has('message')): ?>
    <div class="mt-4"><?= view('components/alert', ['tone' => 'success', 'text' => (string) session('message')], ['saveData' => false]) ?></div>
<?php endif; ?>
<?php if (session()->has('error')): ?>
    <div class="mt-4"><?= view('components/alert', ['tone' => 'danger', 'text' => (string) session('error')], ['saveData' => false]) ?></div>
<?php endif; ?>

<div class="mt-6">
    <?= view('components/data-table', [
        'caption'    => 'Backups',
        'columns'    => [
            ['key' => 'asked', 'label' => 'Asked'],
            ['key' => 'trigger', 'label' => 'Trigger'],
            ['key' => 'status', 'label' => 'Status'],
            ['key' => 'file', 'label' => 'File'],
            ['key' => 'offsite', 'label' => 'Off-site copy'],
            ['key' => 'size', 'label' => 'Size'],
            ['key' => 'finished', 'label' => 'Finished'],
            ['key' => 'by', 'label' => 'By'],
        ],
        'rows'       => $backupTable,
        'emptyIcon'  => 'folder',
        'emptyTitle' => 'No backups recorded yet',
        'emptyText'  => 'Use "Back up now", or let the server\'s scheduled job take them; each one is listed here.',
    ], ['saveData' => false]) ?>
</div>

<?= $this->endSection() ?>
