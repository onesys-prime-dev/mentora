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

foreach ($exportRows as $row) {
    $live = $row['status'] === 'ready' && (string) $row['expires_at'] > date('Y-m-d H:i:s');

    $rows[] = [
        'id'      => $row['id'],
        'cells'   => [
            'taken'   => substr((string) $row['created_at'], 0, 16),
            'by'      => (string) $row['requested_by_label'],
            'size'    => number_format(((int) $row['size_bytes']) / 1024, 1) . ' KB',
            'expires' => substr((string) $row['expires_at'], 0, 16),
            'state'   => ['html' => view('components/status-badge', ['label' => $live ? 'Ready' : 'Expired', 'tone' => $live ? 'success' : 'neutral'], ['saveData' => false])],
        ],
        'actions' => $live
            ? '<a class="font-semibold text-brand hover:underline" data-export="ready" href="' . esc(site_url('institution/export/' . $row['id'])) . '">Download</a>'
            : '<span class="text-xs text-[#646464]" data-export="expired">Expired</span>',
    ];
}
?>

<?= $this->extend('layouts/app') ?>

<?= $this->section('content') ?>

<div class="py-3">

<?php // The way back stays on the background, above the panel, not in the box. ?>
<?= view('components/back-link', ['backUrl' => site_url('institution/dashboard'), 'backLabel' => 'Back to home'], ['saveData' => false]) ?>

<div class="flex flex-col gap-6 rounded-3xl bg-white p-5 shadow-sm sm:p-8" data-page-panel>

    <?= view('components/page-head', [
        'title'       => 'Data export',
        'icon'        => 'folder',
        'description' => 'A copy of everything ' . $exportName . ' holds in Mentora, as CSV files in one ZIP: terms, departments, programs, courses, people, enrolments, final grades, assessments, submissions and announcements. Uploaded files and passwords are not included.',
    ], ['saveData' => false]) ?>

    <?php if (session()->has('message')): ?>
        <?= view('components/alert', ['tone' => 'success', 'text' => (string) session('message')], ['saveData' => false]) ?>
    <?php endif; ?>
    <?php if (session()->has('error')): ?>
        <?= view('components/alert', ['tone' => 'danger', 'text' => (string) session('error')], ['saveData' => false]) ?>
    <?php endif; ?>

    <?php if ($exportAllowed): ?>
        <form class="flex flex-wrap items-center gap-3 rounded-2xl border border-[#E3E6EE] bg-[#F6F7FA] p-4 shadow-md" method="post" action="<?= site_url('institution/export') ?>" data-export-form>
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
        <div class="rounded-2xl border border-[#E3E6EE] bg-[#F6F7FA]" data-export-empty>
            <?= view('components/empty-state', ['icon' => 'folder', 'title' => 'No exports yet', 'text' => $exportAllowed ? 'Create one above and it appears here.' : 'Exports you take will be listed here.'], ['saveData' => false]) ?>
        </div>
    <?php else: ?>
        <?= view('components/data-table', [
            'caption'  => 'Your exports',
            'columns'  => [
                ['key' => 'taken', 'label' => 'Taken'],
                ['key' => 'by', 'label' => 'By'],
                ['key' => 'size', 'label' => 'Size'],
                ['key' => 'expires', 'label' => 'Available until'],
                ['key' => 'state', 'label' => 'State'],
            ],
            'rows'     => $rows,
        ], ['saveData' => false]) ?>
    <?php endif; ?>

</div>

</div>

<?= $this->endSection() ?>
