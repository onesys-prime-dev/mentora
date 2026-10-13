<?php
/**
 * One institution's SIS connection. Credentials are write-only: this page is
 * only ever told whether they are set.
 *
 * @var array<string, mixed>       $sisInstitution
 * @var array<string, mixed>       $sisConnection from SisConnectionService::view()
 * @var array<string, mixed>       $sisType       label and credential fields of the connector
 * @var list<array<string, mixed>> $sisRuns       newest first
 * @var array<string, mixed>|null  $sisLatest     the newest preview or import, with its rejected rows
 */
$th    = 'px-3 py-2 text-left text-xs font-semibold uppercase tracking-wide text-[#646464]';
$input = 'rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm focus:border-brand/60 focus:outline-none focus:ring-2 focus:ring-brand/40';
$badge = ['running' => 'bg-[#E0E5F2] text-brand-dark', 'succeeded' => 'bg-[#E8F5EC] text-[#2B7549]', 'failed' => 'bg-[#FDEDEC] text-[#C33939]'];
$base  = 'admin/institutions/' . $sisInstitution['id'] . '/integrations';
?>

<?= $this->extend('layouts/admin') ?>

<?= $this->section('content') ?>

<?= view('components/page-head', ['title' => 'Integrations', 'icon' => 'link', 'backUrl' => site_url('admin/institutions'), 'backLabel' => 'Institutions'], ['saveData' => false]) ?>
<p class="mt-1 text-sm text-[#646464]"><?= esc($sisInstitution['name']) ?> <span class="font-mono text-xs">(<?= esc($sisInstitution['code']) ?>)</span></p>

<?= view('pages/admin/partials/institution-tabs', ['tabInstitution' => $sisInstitution, 'tabActive' => 'integrations']) ?>

<?php if (session()->has('message')): ?>
    <div class="mt-4"><?= view('components/alert', ['tone' => 'success', 'text' => (string) session('message')], ['saveData' => false]) ?></div>
<?php endif; ?>
<?php if (session()->has('error')): ?>
    <div class="mt-4"><?= view('components/alert', ['tone' => 'danger', 'text' => (string) session('error')], ['saveData' => false]) ?></div>
<?php endif; ?>

<?php if (! $sisConnection['flagOn']): ?>
    <p class="mt-4 rounded bg-amber-50 px-3 py-2 text-sm text-amber-800" data-sis-flag="off">
        The <span class="font-mono">sis.sync</span> feature is off for this institution, so the connection cannot be enabled.
        <a class="font-semibold text-brand hover:underline" href="<?= site_url('admin/institutions/' . $sisInstitution['id'] . '/flags') ?>">Open its features</a>
    </p>
<?php endif; ?>

<section class="mt-6 rounded-2xl bg-white p-6 shadow-sm" data-section="connection">
    <h2 class="text-lg font-semibold text-brand">Student information system</h2>
    <p class="mt-1 text-sm text-[#646464]">Connector: <?= esc($sisType['label']) ?>.</p>
    <dl class="mt-3 grid gap-2 text-sm sm:grid-cols-2">
        <div><dt class="text-xs text-[#646464]">Inbound folder</dt><dd class="break-all font-mono text-xs"><?= esc($sisConnection['folders']['inbound']) ?></dd></div>
        <div><dt class="text-xs text-[#646464]">Outbound folder</dt><dd class="break-all font-mono text-xs"><?= esc($sisConnection['folders']['outbound']) ?></dd></div>
    </dl>

    <form data-dialog-form class="mt-5 flex flex-col gap-4" method="post" action="<?= site_url($base) ?>" autocomplete="off" data-sis-form>
        <?= csrf_field() ?>

        <label class="flex items-center gap-2 text-sm">
            <input type="checkbox" name="enabled" value="1" <?= $sisConnection['enabled'] ? 'checked' : '' ?>>
            <span>Connection enabled</span>
        </label>

        <?php foreach ($sisType['fields'] as $name => $field): ?>
            <label class="flex max-w-md flex-col gap-1 text-xs font-medium text-[#646464]">
                <?= esc($field['label']) ?>
                <input class="<?= $input ?>" type="password" name="<?= esc($name) ?>" value="" autocomplete="new-password" placeholder="<?= $sisConnection['hasCredentials'] ? 'Saved. Type to replace.' : 'Not set' ?>" data-secret="<?= esc($name) ?>">
                <span class="font-normal text-[#646464]"><?= esc($field['help']) ?></span>
            </label>
        <?php endforeach; ?>

        <?php if ($sisConnection['hasCredentials']): ?>
            <p class="text-xs text-[#646464]" data-credentials="set">Credentials are saved (last changed <?= esc(substr((string) $sisConnection['credentialsSetAt'], 0, 16)) ?>). They cannot be shown again.</p>
            <label class="flex items-center gap-2 text-xs text-[#646464]">
                <input type="checkbox" name="clear_credentials" value="1"> Remove the saved credentials
            </label>
        <?php else: ?>
            <p class="text-xs text-[#646464]" data-credentials="none">No credentials saved.</p>
        <?php endif; ?>

        <div><button type="submit" class="rounded-full bg-brand px-5 py-2 text-sm font-semibold text-white transition hover:bg-brand-dark focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/50">Save (asks for your password)</button></div>
    </form>

    <form class="mt-4" method="post" action="<?= site_url($base . '/test') ?>">
        <?= csrf_field() ?>
        <button type="submit" class="rounded-full border border-brand bg-white px-5 py-2 text-sm font-semibold text-brand transition hover:bg-brand-tint focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/50">Test connection</button>
    </form>
</section>

<section class="mt-6 rounded-2xl bg-white p-6 shadow-sm" data-section="import">
    <h2 class="text-lg font-semibold text-brand">Import from the SIS</h2>
    <p class="mt-1 text-sm text-[#646464]">People, courses and enrolments come in from the SIS, which wins on any difference. A preview does the whole job and then undoes it, so you see the counts and any rows that would be refused. Run one before the first real import.</p>
    <div class="mt-4 flex flex-wrap gap-3">
        <form method="post" action="<?= site_url($base . '/preview') ?>">
            <?= csrf_field() ?>
            <button type="submit" class="rounded-full border border-brand bg-white px-5 py-2 text-sm font-semibold text-brand transition hover:bg-brand-tint focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/50" data-sis-preview>Preview (dry run)</button>
        </form>
        <form data-dialog-form method="post" action="<?= site_url($base . '/run') ?>">
            <?= csrf_field() ?>
            <button type="submit" class="rounded-full bg-brand px-5 py-2 text-sm font-semibold text-white transition hover:bg-brand-dark focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/50 disabled:opacity-50" <?= $sisConnection['enabled'] ? '' : 'disabled' ?> data-sis-run>Import now</button>
        </form>
    </div>

    <?php if ($sisLatest !== null): ?>
        <h3 class="mt-5 text-sm font-semibold text-gray-800" data-latest-import>
            Latest <?= (int) $sisLatest['run']['dry_run'] === 1 ? 'preview' : 'import' ?>:
            <?= esc((string) $sisLatest['run']['message']) ?>
        </h3>
        <?php if ($sisLatest['issues'] !== []): ?>
            <div class="mt-2 overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200 text-sm" data-issues>
                    <thead class="bg-gray-50"><tr><th class="<?= $th ?>">File</th><th class="<?= $th ?>">Record</th><th class="<?= $th ?>">Why it was not imported</th></tr></thead>
                    <tbody class="divide-y divide-gray-100">
                        <?php foreach ($sisLatest['issues'] as $issue): ?>
                            <tr><td class="px-3 py-2"><?= esc($issue['dataset']) ?>.csv</td><td class="px-3 py-2 font-mono text-xs"><?= esc($issue['item']) ?></td><td class="px-3 py-2"><?= esc($issue['reason']) ?></td></tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php if ($sisLatest['truncated']): ?>
                <p class="mt-2 text-xs text-[#646464]">Only the first <?= esc((string) \App\Services\Sis\SisImporter::MAX_ISSUES) ?> are listed; the counts cover every row.</p>
            <?php endif; ?>
        <?php endif; ?>
    <?php endif; ?>
</section>

<section class="mt-6 rounded-2xl bg-white p-6 shadow-sm" data-section="grades">
    <h2 class="text-lg font-semibold text-brand">Final grades to the SIS</h2>
    <p class="mt-1 text-sm text-[#646464]">Only final grades go out (course, student, percentage, remarks), and only the ones that are new or changed since they were last sent. Mentora is the source of truth: the SIS receives what is here. Sending the same grades again replaces the delivery, so nothing is duplicated.</p>
    <div class="mt-4 flex flex-wrap gap-3">
        <form method="post" action="<?= site_url($base . '/grades/preview') ?>">
            <?= csrf_field() ?>
            <button type="submit" class="rounded-full border border-brand bg-white px-5 py-2 text-sm font-semibold text-brand transition hover:bg-brand-tint focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/50" data-grades-preview>Preview what would be sent</button>
        </form>
        <form data-dialog-form method="post" action="<?= site_url($base . '/grades/send') ?>">
            <?= csrf_field() ?>
            <button type="submit" class="rounded-full bg-brand px-5 py-2 text-sm font-semibold text-white transition hover:bg-brand-dark focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/50 disabled:opacity-50" <?= $sisConnection['enabled'] ? '' : 'disabled' ?> data-grades-send>Send final grades now</button>
        </form>
    </div>
</section>

<section class="mt-6 rounded-2xl bg-white p-6 shadow-sm" data-section="runs">
    <h2 class="text-lg font-semibold text-brand">Run history</h2>
    <div class="mt-3 overflow-x-auto">
        <table class="min-w-full divide-y divide-gray-200 text-sm">
            <thead class="bg-gray-50">
                <tr>
                    <th class="<?= $th ?>">Started</th><th class="<?= $th ?>">Direction</th><th class="<?= $th ?>">Trigger</th><th class="<?= $th ?>">Result</th>
                    <th class="<?= $th ?>">Created</th><th class="<?= $th ?>">Updated</th><th class="<?= $th ?>">Skipped</th><th class="<?= $th ?>">Failed</th><th class="<?= $th ?>">Message</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                <?php if ($sisRuns === []): ?>
                    <tr><td colspan="9" class="px-3 py-6 text-center text-[#646464]" data-runs-empty>Nothing has run yet.</td></tr>
                <?php endif; ?>
                <?php foreach ($sisRuns as $run): ?>
                    <tr data-run="<?= esc($run['status']) ?>">
                        <td class="px-3 py-2"><?= esc(substr((string) $run['started_at'], 0, 16)) ?></td>
                        <td class="px-3 py-2"><?= esc(ucfirst((string) $run['direction'])) ?><?= (int) $run['dry_run'] === 1 ? ' (dry run)' : '' ?></td>
                        <td class="px-3 py-2"><?= esc(ucfirst((string) $run['trigger'])) ?></td>
                        <td class="px-3 py-2"><span class="rounded-full px-2 py-0.5 text-xs font-semibold <?= $badge[$run['status']] ?? '' ?>"><?= esc(ucfirst((string) $run['status'])) ?></span></td>
                        <td class="px-3 py-2"><?= esc((string) $run['created_count']) ?></td>
                        <td class="px-3 py-2"><?= esc((string) $run['updated_count']) ?></td>
                        <td class="px-3 py-2"><?= esc((string) $run['skipped_count']) ?></td>
                        <td class="px-3 py-2"><?= esc((string) $run['failed_count']) ?></td>
                        <td class="px-3 py-2 text-xs text-[#646464]"><?= esc((string) $run['message']) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>

<?= $this->endSection() ?>
