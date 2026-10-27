<?php
/**
 * The institutional administrator's data export.
 *
 * @var string                     $exportName
 * @var bool                       $exportAllowed
 * @var list<array<string, mixed>> $exportRows
 * @var int                        $exportDays
 */
$rows = [];

// One export is prepared at a time (12.3); every administrator sees it here (12.4).
$preparing = array_filter($exportRows, static fn (array $row): bool => in_array($row['status'], ['queued', 'running'], true)) !== [];

foreach ($exportRows as $row) {
    $open = in_array($row['status'], ['queued', 'running'], true);
    $live = $row['status'] === 'ready' && (string) $row['expires_at'] > date('Y-m-d H:i:s');

    [$label, $tone] = match (true) {
        $open                       => ['Being prepared', 'info'],
        $row['status'] === 'failed' => ['Failed', 'danger'],
        $live                       => ['Ready', 'success'],
        default                     => ['Expired', 'neutral'],
    };

    $rows[] = [
        'id'      => $row['id'],
        'cells'   => [
            'taken'   => substr((string) $row['created_at'], 0, 16),
            'by'      => (string) $row['requested_by_label'],
            'size'    => $row['size_bytes'] === null ? '—' : number_format(((int) $row['size_bytes']) / 1024, 1) . ' KB',
            'expires' => $row['expires_at'] === null ? '—' : substr((string) $row['expires_at'], 0, 16),
            // Why it failed, under its badge, as the backups page does.
            'state'   => ['html' => '<span data-export-state="' . esc($row['status']) . '">' . view('components/status-badge', ['label' => $label, 'tone' => $tone], ['saveData' => false]) . '</span>'
                . ($row['status'] === 'failed' && ! empty($row['message']) ? '<span class="mt-1 block text-xs text-[#C33939]">' . esc((string) $row['message']) . '</span>' : '')],
        ],
        'actions' => match (true) {
            $live                       => '<a class="font-semibold text-brand hover:underline" data-export="ready" href="' . esc(site_url('institution/export/' . $row['id'])) . '">Download</a>',
            $open                       => '<span class="text-xs text-[#646464]" data-export="preparing">Being prepared</span>',
            $row['status'] === 'failed' => '<span class="text-xs text-[#C33939]" data-export="failed">Failed</span>',
            default                     => '<span class="text-xs text-[#646464]" data-export="expired">Expired</span>',
        },
    ];
}
?>

<?= $this->extend('layouts/app') ?>

<?= $this->section('content') ?>

<div class="flex flex-col gap-6 py-3">

    <?= view('components/page-head', [
        'title'       => 'Data export',
        'icon'        => 'folder',
        'description' => 'A copy of everything ' . $exportName . ' holds in Mentora, as CSV files in one ZIP: terms, departments, programs, courses, people, enrolments, final grades, assessments, submissions and announcements. Uploaded files and passwords are not included.',
        'backUrl'     => site_url('institution/dashboard'),
        'backLabel'   => 'Back to home',
    ], ['saveData' => false]) ?>

    <?php if (session()->has('message')): ?>
        <?= view('components/alert', ['tone' => 'success', 'text' => (string) session('message')], ['saveData' => false]) ?>
    <?php endif; ?>
    <?php if (session()->has('error')): ?>
        <?= view('components/alert', ['tone' => 'danger', 'text' => (string) session('error')], ['saveData' => false]) ?>
    <?php endif; ?>

    <?php if ($exportAllowed && $preparing): ?>
        <div class="flex flex-wrap items-center gap-3 rounded-2xl bg-white p-4 shadow-sm" data-export-preparing>
            <?= view('components/status-badge', ['label' => 'Being prepared', 'tone' => 'info'], ['saveData' => false]) ?>
            <span class="text-sm text-[#3D3D3D]">An export is being prepared. Whoever asked for it is told here and by email when it's ready.</span>
        </div>
    <?php elseif ($exportAllowed): ?>
        <form class="flex flex-wrap items-center gap-3 rounded-2xl bg-white p-4 shadow-sm" method="post" action="<?= site_url('institution/export') ?>" data-export-form>
            <?= csrf_field() ?>
            <button type="submit" class="rounded-full bg-brand px-5 py-2 text-sm font-semibold text-white transition hover:bg-brand-dark focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/50">Create an export</button>
            <span class="text-xs text-[#646464]">Each export can be downloaded for <?= esc((string) $exportDays) ?> days.</span>
        </form>
    <?php else: ?>
        <div data-export-unavailable>
            <?= view('components/alert', ['tone' => 'info', 'title' => 'Not available yet', 'text' => 'A full export is available once the subscription has ended, while the institution is read-only or past.'], ['saveData' => false]) ?>
        </div>
    <?php endif; ?>

    <?php if ($rows === []): ?>
        <div class="rounded-2xl bg-white/70" data-export-empty>
            <?= view('components/empty-state', ['icon' => 'folder', 'title' => 'No exports yet', 'text' => $exportAllowed ? 'Create one above and it appears here.' : 'Exports you take will be listed here.'], ['saveData' => false]) ?>
        </div>
    <?php else: ?>
        <?= view('components/data-table', [
            'caption'  => 'Your exports',
            'columns'  => [
                ['key' => 'taken', 'label' => 'Asked'],
                ['key' => 'by', 'label' => 'By'],
                ['key' => 'size', 'label' => 'Size'],
                ['key' => 'expires', 'label' => 'Available until'],
                ['key' => 'state', 'label' => 'State'],
            ],
            'rows'     => $rows,
        ], ['saveData' => false]) ?>
    <?php endif; ?>

</div>

<?= $this->endSection() ?>
