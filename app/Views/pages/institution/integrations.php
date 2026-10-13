<?php
/**
 * The institution's student information system connection, as its administrator sees it.
 *
 * @var array<string, mixed> $sisConnection  available, configured, enabled, label, runs, latest
 * @var bool                 $sisCanRun
 */
$c       = $sisConnection;
$base    = 'institution/integrations';
$primary = 'rounded-full bg-brand px-5 py-2 text-sm font-semibold text-white transition hover:bg-brand-dark focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/50 disabled:cursor-not-allowed disabled:opacity-50';
$second  = 'rounded-full border border-brand bg-white px-5 py-2 text-sm font-semibold text-brand transition hover:bg-brand-tint focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/50 disabled:cursor-not-allowed disabled:opacity-50';

$ready   = $c['available'] && $c['configured'];
$running = $ready && $c['enabled'] && $sisCanRun;

$tones = ['succeeded' => 'success', 'failed' => 'danger', 'running' => 'info'];

$rows = [];

foreach ($c['runs'] as $i => $run) {
    $rows[] = [
        'id'    => $i,
        'label' => ucfirst($run['direction']),
        'cells' => [
            'started'   => date('j M Y H:i', strtotime($run['started_at'])),
            'what'      => ($run['direction'] === 'inbound' ? 'Import' : 'Grades sent') . ($run['dry_run'] ? ' (preview)' : ''),
            'how'       => ucfirst($run['trigger']),
            'result'    => ['html' => view('components/status-badge', ['label' => ucfirst($run['status']), 'tone' => $tones[$run['status']] ?? 'neutral'], ['saveData' => false])],
            'created'   => (string) $run['created'],
            'updated'   => (string) $run['updated'],
            'skipped'   => (string) $run['skipped'],
            'failed'    => (string) $run['failed'],
            'message'   => $run['message'],
        ],
    ];
}

$issueRows = [];

if ($c['latest'] !== null) {
    foreach ($c['latest']['issues'] as $i => $issue) {
        $issueRows[] = ['id' => $i, 'label' => $issue['item'], 'cells' => ['file' => $issue['dataset'] . '.csv', 'record' => $issue['item'], 'reason' => $issue['reason']]];
    }
}
?>

<?= $this->extend('layouts/app') ?>

<?= $this->section('content') ?>

<div class="flex flex-col gap-6 py-3">

    <?= view('components/page-head', [
        'title'       => 'Integrations',
        'icon'        => 'link',
        'description' => 'People, courses and enrolments from your student information system, and final grades back to it.',
        'backUrl'     => site_url('institution/dashboard'),
        'backLabel'   => 'Back to home',
    ], ['saveData' => false]) ?>

    <?php if (session()->has('message')): ?>
        <?= view('components/alert', ['tone' => 'success', 'text' => (string) session('message')], ['saveData' => false]) ?>
    <?php endif; ?>
    <?php if (session()->has('error')): ?>
        <?= view('components/alert', ['tone' => 'danger', 'text' => (string) session('error')], ['saveData' => false]) ?>
    <?php endif; ?>

    <section class="rounded-2xl bg-white p-6 shadow-sm" aria-labelledby="state-heading" data-connection>
        <div class="flex flex-wrap items-center gap-3">
            <h2 id="state-heading" class="text-lg font-bold text-brand"><?= esc($c['label']) ?></h2>
            <?php if (! $c['available']): ?>
                <?= view('components/status-badge', ['label' => 'Not available', 'tone' => 'neutral'], ['saveData' => false]) ?>
            <?php elseif (! $c['configured']): ?>
                <?= view('components/status-badge', ['label' => 'Not set up', 'tone' => 'neutral'], ['saveData' => false]) ?>
            <?php elseif ($c['enabled']): ?>
                <?= view('components/status-badge', ['label' => 'Connected', 'tone' => 'success'], ['saveData' => false]) ?>
            <?php else: ?>
                <?= view('components/status-badge', ['label' => 'Set up, switched off', 'tone' => 'warning'], ['saveData' => false]) ?>
            <?php endif; ?>
        </div>

        <p class="mt-3 text-sm text-[#3D3D3D]" data-connection-text>
            <?php if (! $c['available']): ?>
                The connection to a student information system is not part of your plan yet. Ask your Mentora contact if you would like it.
            <?php elseif (! $c['configured']): ?>
                Your Mentora contact sets up the connection. Once it is set up you can preview and run imports here.
            <?php elseif (! $c['enabled']): ?>
                The connection is set up but switched off, so only previews can run. Your Mentora contact switches it on.
            <?php else: ?>
                The SIS is the source of truth for the people, courses and enrolments it supplies: they are not edited by hand here, and its changes win. Mentora is the source of truth for final grades.
            <?php endif; ?>
        </p>
        <p class="mt-2 text-xs text-[#646464]">The connection's settings and credentials are managed by your Mentora contact and are not shown here.</p>
    </section>

    <?php if ($ready): ?>

        <section class="rounded-2xl bg-white p-6 shadow-sm" aria-labelledby="import-heading" data-section="import">
            <h2 id="import-heading" class="text-sm font-bold text-brand">Import from the SIS</h2>
            <p class="mt-1 text-sm text-[#3D3D3D]">A preview does the whole job and then undoes it, so you see the counts and any rows that would be refused. Run one before the first real import. A real import asks for your password.</p>

            <div class="mt-4 flex flex-wrap gap-3">
                <form method="post" action="<?= esc(site_url($base . '/preview')) ?>"><?= csrf_field() ?>
                    <button type="submit" class="<?= $second ?>" data-sis-preview>Preview (dry run)</button>
                </form>
                <form method="post" action="<?= esc(site_url($base . '/run')) ?>"><?= csrf_field() ?>
                    <button type="submit" class="<?= $primary ?>" data-sis-run <?= $running ? '' : 'disabled' ?>>Import now</button>
                </form>
            </div>

            <?php if (! $running && $c['configured'] && $c['enabled'] && ! $sisCanRun): ?>
                <p class="mt-3"><?= view('components/lock-note', ['kind' => 'read_only'], ['saveData' => false]) ?></p>
            <?php endif; ?>

            <?php if ($c['latest'] !== null): ?>
                <h3 class="mt-5 text-sm font-semibold text-[#1A1C1E]" data-latest-import>
                    Latest <?= $c['latest']['dry_run'] ? 'preview' : 'import' ?>: <?= esc($c['latest']['message']) ?>
                </h3>

                <?php if ($issueRows !== []): ?>
                    <div class="mt-3" data-issues>
                        <?= view('components/data-table', [
                            'caption' => 'Rows that were not imported',
                            'columns' => [['key' => 'file', 'label' => 'File'], ['key' => 'record', 'label' => 'Record'], ['key' => 'reason', 'label' => 'Why it was not imported']],
                            'rows'    => $issueRows,
                        ], ['saveData' => false]) ?>
                        <?php if ($c['latest']['truncated']): ?>
                            <p class="mt-2 text-xs text-[#646464]">Only the first <?= esc((string) \App\Services\Sis\SisImporter::MAX_ISSUES) ?> are listed; the counts cover every row.</p>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </section>

        <section class="rounded-2xl bg-white p-6 shadow-sm" aria-labelledby="grades-heading" data-section="grades">
            <h2 id="grades-heading" class="text-sm font-bold text-brand">Final grades to the SIS</h2>
            <p class="mt-1 text-sm text-[#3D3D3D]">Only final grades go out (course, student, percentage, remarks), and only those new or changed since they were last sent. Sending the same grades again replaces the delivery, so nothing is duplicated. Sending asks for your password.</p>

            <div class="mt-4 flex flex-wrap gap-3">
                <form method="post" action="<?= esc(site_url($base . '/grades/preview')) ?>"><?= csrf_field() ?>
                    <button type="submit" class="<?= $second ?>" data-grades-preview>Preview what would be sent</button>
                </form>
                <form method="post" action="<?= esc(site_url($base . '/grades/send')) ?>"><?= csrf_field() ?>
                    <button type="submit" class="<?= $primary ?>" data-grades-send <?= $running ? '' : 'disabled' ?>>Send final grades now</button>
                </form>
            </div>
        </section>

    <?php endif; ?>

    <section class="flex flex-col gap-3" aria-labelledby="runs-heading" data-section="runs">
        <h2 id="runs-heading" class="text-sm font-bold text-brand">What has run</h2>

        <?= view('components/data-table', [
            'caption'    => 'Run history',
            'columns'    => [
                ['key' => 'started', 'label' => 'Started'],
                ['key' => 'what', 'label' => 'What'],
                ['key' => 'how', 'label' => 'How'],
                ['key' => 'result', 'label' => 'Result'],
                ['key' => 'created', 'label' => 'Created'],
                ['key' => 'updated', 'label' => 'Updated'],
                ['key' => 'skipped', 'label' => 'Unchanged'],
                ['key' => 'failed', 'label' => 'Refused'],
                ['key' => 'message', 'label' => 'Message'],
            ],
            'rows'       => $rows,
            'emptyIcon'  => 'link',
            'emptyTitle' => 'Nothing has run yet',
            'emptyText'  => 'Imports and grade deliveries are listed here, newest first.',
        ], ['saveData' => false]) ?>
    </section>

</div>

<?= $this->endSection() ?>
