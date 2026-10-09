<?php
/**
 * The archive: past institutions and deleted institutions.
 *
 * @var list<array<string, mixed>> $pastRows
 * @var list<array<string, mixed>> $deletedRows each with purge_on, days_left and contents
 * @var int                        $retentionDays
 */
$th    = 'px-3 py-2 text-left text-xs font-semibold uppercase tracking-wide text-gray-600';
$btn   = 'rounded border px-2.5 py-1 text-xs font-semibold';
$input = 'rounded border border-gray-300 bg-white px-2 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-[#0040A6]/55';
?>

<?= $this->extend('layouts/admin') ?>

<?= $this->section('content') ?>

<h1 class="text-2xl font-bold text-[#0040A6]">Archive</h1>
<p class="mt-2 max-w-3xl text-sm text-gray-600">
    Institutions that are no longer running. Past institutions keep all their data, read-only, until they are renewed.
    A deleted institution can be restored for <?= esc((string) $retentionDays) ?> days; after that it is purged for good.
</p>

<?php if (session()->has('message')): ?>
    <p class="mt-4 rounded bg-green-50 px-3 py-2 text-sm text-green-700" role="status"><?= esc((string) session('message')) ?></p>
<?php endif; ?>
<?php if (session()->has('error')): ?>
    <p class="mt-4 rounded bg-red-50 px-3 py-2 text-sm text-red-700" role="alert"><?= esc((string) session('error')) ?></p>
<?php endif; ?>

<h2 class="mt-8 text-lg font-semibold text-[#0040A6]">Past institutions</h2>
<div class="mt-2 overflow-x-auto rounded-lg bg-white shadow">
    <table class="min-w-full divide-y divide-gray-200 text-sm">
        <thead class="bg-gray-50">
            <tr>
                <th class="<?= $th ?>">Name</th>
                <th class="<?= $th ?>">Code</th>
                <th class="<?= $th ?>">Last changed</th>
                <th class="<?= $th ?>">Actions</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-gray-100">
            <?php if ($pastRows === []): ?>
                <tr><td class="px-3 py-6 text-center text-gray-500" colspan="4">No past institutions.</td></tr>
            <?php endif; ?>
            <?php foreach ($pastRows as $row): ?>
                <tr>
                    <td class="px-3 py-2 font-medium"><?= esc($row['name']) ?></td>
                    <td class="px-3 py-2 font-mono text-xs"><?= esc($row['code']) ?></td>
                    <td class="whitespace-nowrap px-3 py-2 text-gray-600"><?= esc(substr((string) $row['updated_at'], 0, 10)) ?></td>
                    <td class="px-3 py-2"><a class="<?= $btn ?> border-gray-300 text-gray-700 hover:bg-gray-50" href="<?= site_url('admin/institutions/' . $row['id'] . '/edit') ?>">Edit</a></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<h2 class="mt-8 text-lg font-semibold text-[#0040A6]">Deleted institutions</h2>
<div class="mt-2 overflow-x-auto rounded-lg bg-white shadow">
    <table class="min-w-full divide-y divide-gray-200 text-sm">
        <thead class="bg-gray-50">
            <tr>
                <th class="<?= $th ?>">Name</th>
                <th class="<?= $th ?>">Code</th>
                <th class="<?= $th ?>">Deleted</th>
                <th class="<?= $th ?>">Purged on</th>
                <th class="<?= $th ?>">Actions</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-gray-100">
            <?php if ($deletedRows === []): ?>
                <tr><td class="px-3 py-6 text-center text-gray-500" colspan="5">No deleted institutions.</td></tr>
            <?php endif; ?>
            <?php foreach ($deletedRows as $row): ?>
                <?php $c = $row['contents']; ?>
                <tr>
                    <td class="px-3 py-2 font-medium"><?= esc($row['name']) ?></td>
                    <td class="px-3 py-2 font-mono text-xs"><?= esc($row['code']) ?></td>
                    <td class="whitespace-nowrap px-3 py-2 text-gray-600"><?= esc(substr((string) $row['deleted_at'], 0, 10)) ?></td>
                    <td class="whitespace-nowrap px-3 py-2">
                        <?= esc($row['purge_on']) ?>
                        <span class="block text-xs <?= $row['days_left'] === 0 ? 'text-red-700' : 'text-gray-500' ?>">
                            <?= $row['days_left'] === 0 ? 'due now' : esc((string) $row['days_left']) . ' day' . ($row['days_left'] === 1 ? '' : 's') . ' left' ?>
                        </span>
                    </td>
                    <td class="px-3 py-2">
                        <div class="flex flex-wrap items-start gap-2">
                            <form method="post" action="<?= site_url('admin/institutions/' . $row['id'] . '/restore') ?>">
                                <?= csrf_field() ?>
                                <input type="hidden" name="return" value="archive">
                                <button type="submit" class="<?= $btn ?> border-[#0040A6] text-[#0040A6] hover:bg-[#0040A6]/10">Restore</button>
                            </form>

                            <details>
                                <summary class="<?= $btn ?> cursor-pointer border-red-400 text-red-700 hover:bg-red-50">Purge now</summary>
                                <form method="post" action="<?= site_url('admin/institutions/' . $row['id'] . '/purge') ?>" class="mt-2 flex max-w-xs flex-col gap-2">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="return" value="archive">
                                    <p class="text-xs text-red-700">
                                        This cannot be undone. It permanently removes the institution and everything in it:
                                        <?= esc((string) $c['departments']) ?> department(s), <?= esc((string) $c['programs']) ?> program(s),
                                        <?= esc((string) $c['courses']) ?> course(s) and all their offerings, enrolments, work and grades.
                                        <?= esc((string) $c['members']) ?> member account(s) are kept<?php if ($c['members_left_without_an_institution'] > 0): ?>,
                                        <?= esc((string) $c['members_left_without_an_institution']) ?> of them with no institution left<?php endif; ?>.
                                    </p>
                                    <label class="text-xs text-gray-600" for="purge-<?= esc((string) $row['id']) ?>">Type <strong><?= esc($row['name']) ?></strong> to confirm</label>
                                    <input id="purge-<?= esc((string) $row['id']) ?>" class="<?= $input ?>" type="text" name="confirm_name" autocomplete="off" required>
                                    <button type="submit" class="<?= $btn ?> border-red-600 bg-red-600 text-white hover:bg-red-700">Purge permanently</button>
                                </form>
                            </details>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<?= $this->endSection() ?>
