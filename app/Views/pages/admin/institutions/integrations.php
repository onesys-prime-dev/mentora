<?php
/**
 * One institution's SIS connection. Credentials are write-only: this page is
 * only ever told whether they are set.
 *
 * @var array<string, mixed>       $sisInstitution
 * @var array<string, mixed>       $sisConnection from SisConnectionService::view()
 * @var array<string, mixed>       $sisType       label and credential fields of the connector
 * @var list<array<string, mixed>> $sisRuns       newest first
 */
$th    = 'px-3 py-2 text-left text-xs font-semibold uppercase tracking-wide text-gray-600';
$input = 'rounded border border-gray-300 bg-white px-2 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-[#0040A6]/55';
$badge = ['running' => 'bg-blue-100 text-blue-800', 'succeeded' => 'bg-green-100 text-green-800', 'failed' => 'bg-red-100 text-red-800'];
$base  = 'admin/institutions/' . $sisInstitution['id'] . '/integrations';
?>

<?= $this->extend('layouts/admin') ?>

<?= $this->section('content') ?>

<a class="text-sm font-semibold text-[#0040A6] hover:underline" href="<?= site_url('admin/institutions') ?>">&larr; Institutions</a>

<h1 class="mt-2 text-2xl font-bold text-[#0040A6]">Integrations</h1>
<p class="mt-1 text-sm text-gray-600"><?= esc($sisInstitution['name']) ?> <span class="font-mono text-xs">(<?= esc($sisInstitution['code']) ?>)</span></p>

<?= view('pages/admin/partials/institution-tabs', ['tabInstitution' => $sisInstitution, 'tabActive' => 'integrations']) ?>

<?php if (session()->has('message')): ?>
    <p class="mt-4 rounded bg-green-50 px-3 py-2 text-sm text-green-700" role="status"><?= esc((string) session('message')) ?></p>
<?php endif; ?>
<?php if (session()->has('error')): ?>
    <p class="mt-4 rounded bg-red-50 px-3 py-2 text-sm text-red-700" role="alert"><?= esc((string) session('error')) ?></p>
<?php endif; ?>

<?php if (! $sisConnection['flagOn']): ?>
    <p class="mt-4 rounded bg-amber-50 px-3 py-2 text-sm text-amber-800" data-sis-flag="off">
        The <span class="font-mono">sis.sync</span> feature is off for this institution, so the connection cannot be enabled.
        <a class="font-semibold text-[#0040A6] hover:underline" href="<?= site_url('admin/institutions/' . $sisInstitution['id'] . '/flags') ?>">Open its features</a>
    </p>
<?php endif; ?>

<section class="mt-6 rounded-lg bg-white p-6 shadow" data-section="connection">
    <h2 class="text-lg font-semibold text-[#0040A6]">Student information system</h2>
    <p class="mt-1 text-sm text-gray-600">Connector: <?= esc($sisType['label']) ?>.</p>
    <dl class="mt-3 grid gap-2 text-sm sm:grid-cols-2">
        <div><dt class="text-xs text-gray-500">Inbound folder</dt><dd class="break-all font-mono text-xs"><?= esc($sisConnection['folders']['inbound']) ?></dd></div>
        <div><dt class="text-xs text-gray-500">Outbound folder</dt><dd class="break-all font-mono text-xs"><?= esc($sisConnection['folders']['outbound']) ?></dd></div>
    </dl>

    <form class="mt-5 flex flex-col gap-4" method="post" action="<?= site_url($base) ?>" autocomplete="off" data-sis-form>
        <?= csrf_field() ?>

        <label class="flex items-center gap-2 text-sm">
            <input type="checkbox" name="enabled" value="1" <?= $sisConnection['enabled'] ? 'checked' : '' ?>>
            <span>Connection enabled</span>
        </label>

        <?php foreach ($sisType['fields'] as $name => $field): ?>
            <label class="flex max-w-md flex-col gap-1 text-xs font-medium text-gray-600">
                <?= esc($field['label']) ?>
                <input class="<?= $input ?>" type="password" name="<?= esc($name) ?>" value="" autocomplete="new-password" placeholder="<?= $sisConnection['hasCredentials'] ? 'Saved. Type to replace.' : 'Not set' ?>" data-secret="<?= esc($name) ?>">
                <span class="font-normal text-gray-500"><?= esc($field['help']) ?></span>
            </label>
        <?php endforeach; ?>

        <?php if ($sisConnection['hasCredentials']): ?>
            <p class="text-xs text-gray-500" data-credentials="set">Credentials are saved (last changed <?= esc(substr((string) $sisConnection['credentialsSetAt'], 0, 16)) ?>). They cannot be shown again.</p>
            <label class="flex items-center gap-2 text-xs text-gray-600">
                <input type="checkbox" name="clear_credentials" value="1"> Remove the saved credentials
            </label>
        <?php else: ?>
            <p class="text-xs text-gray-500" data-credentials="none">No credentials saved.</p>
        <?php endif; ?>

        <div><button type="submit" class="rounded bg-[#0040A6] px-4 py-2 text-sm font-semibold text-white hover:bg-[#002068]">Save (asks for your password)</button></div>
    </form>

    <form class="mt-4" method="post" action="<?= site_url($base . '/test') ?>">
        <?= csrf_field() ?>
        <button type="submit" class="rounded border border-[#0040A6] px-4 py-2 text-sm font-semibold text-[#0040A6] hover:bg-[#0040A6]/10">Test connection</button>
    </form>
</section>

<section class="mt-6 rounded-lg bg-white p-6 shadow" data-section="runs">
    <h2 class="text-lg font-semibold text-[#0040A6]">Run history</h2>
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
                    <tr><td colspan="9" class="px-3 py-6 text-center text-gray-500" data-runs-empty>Nothing has run yet.</td></tr>
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
                        <td class="px-3 py-2 text-xs text-gray-600"><?= esc((string) $run['message']) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>

<?= $this->endSection() ?>
