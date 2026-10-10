<?php
/**
 * @var string                     $exportName
 * @var bool                       $exportAllowed
 * @var list<array<string, mixed>> $exportRows
 * @var int                        $exportDays
 */
$th = 'px-3 py-2 text-left text-xs font-semibold uppercase tracking-wide text-gray-600';
?>

<?= $this->extend('layouts/app') ?>

<?= $this->section('content') ?>

<div class="mx-auto max-w-4xl">
    <h1 class="text-2xl font-bold text-[#0040A6]">Data export</h1>
    <p class="mt-1 text-sm text-gray-600">A copy of everything <?= esc($exportName) ?> holds in Mentora: terms, departments, programs, courses, people, enrolments, final grades, assessments, submissions and announcements, as CSV files in one ZIP. Uploaded files and passwords are not included.</p>

    <?php if (session()->has('message')): ?>
        <p class="mt-4 rounded bg-green-50 px-3 py-2 text-sm text-green-700" role="status"><?= esc((string) session('message')) ?></p>
    <?php endif; ?>
    <?php if (session()->has('error')): ?>
        <p class="mt-4 rounded bg-red-50 px-3 py-2 text-sm text-red-700" role="alert"><?= esc((string) session('error')) ?></p>
    <?php endif; ?>

    <?php if ($exportAllowed): ?>
        <form class="mt-6" method="post" action="<?= site_url('institution/export') ?>" data-export-form>
            <?= csrf_field() ?>
            <button type="submit" class="rounded bg-[#0040A6] px-4 py-2 text-sm font-semibold text-white hover:bg-[#002068]">Create an export</button>
            <span class="ml-2 text-xs text-gray-500">Each export can be downloaded for <?= esc((string) $exportDays) ?> days.</span>
        </form>
    <?php else: ?>
        <p class="mt-6 rounded bg-amber-50 px-3 py-2 text-sm text-amber-800" data-export-unavailable>A full export is available once the subscription has ended, while the institution is read-only or past.</p>
    <?php endif; ?>

    <div class="mt-6 overflow-x-auto rounded-lg bg-white shadow">
        <table class="min-w-full divide-y divide-gray-200 text-sm">
            <thead class="bg-gray-50">
                <tr><th class="<?= $th ?>">Taken</th><th class="<?= $th ?>">By</th><th class="<?= $th ?>">Size</th><th class="<?= $th ?>">Available until</th><th class="<?= $th ?>"></th></tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                <?php if ($exportRows === []): ?>
                    <tr><td colspan="5" class="px-3 py-6 text-center text-gray-500" data-export-empty>No exports yet.</td></tr>
                <?php endif; ?>
                <?php foreach ($exportRows as $row): ?>
                    <?php $live = $row['status'] === 'ready' && (string) $row['expires_at'] > date('Y-m-d H:i:s'); ?>
                    <tr data-export="<?= $live ? 'ready' : 'expired' ?>">
                        <td class="px-3 py-2"><?= esc(substr((string) $row['created_at'], 0, 16)) ?></td>
                        <td class="px-3 py-2"><?= esc((string) $row['requested_by_label']) ?></td>
                        <td class="px-3 py-2"><?= esc(number_format(((int) $row['size_bytes']) / 1024, 1)) ?> KB</td>
                        <td class="px-3 py-2"><?= esc(substr((string) $row['expires_at'], 0, 16)) ?></td>
                        <td class="px-3 py-2 text-right">
                            <?php if ($live): ?>
                                <a class="font-semibold text-[#0040A6] hover:underline" href="<?= site_url('institution/export/' . $row['id']) ?>">Download</a>
                            <?php else: ?>
                                <span class="text-xs text-gray-500">Expired</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?= $this->endSection() ?>
