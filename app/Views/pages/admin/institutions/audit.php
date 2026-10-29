<?php
/**
 * The latest audit entries for one institution.
 *
 * @var array<string, mixed>       $auditInstitution
 * @var list<array<string, mixed>> $auditRows  newest first
 * @var int                        $auditTotal
 */
$th = 'px-3 py-2 text-left text-xs font-semibold uppercase tracking-wide text-[#646464]';
?>

<?= $this->extend('layouts/admin') ?>

<?= $this->section('content') ?>

<div class="rounded-3xl bg-white p-5 shadow-sm sm:p-8" data-page-panel>

<?= view('components/page-head', ['title' => 'Audit', 'icon' => 'tasks', 'backUrl' => site_url('admin/institutions'), 'backLabel' => 'Institutions'], ['saveData' => false]) ?>
<p class="mt-1 text-sm text-[#646464]"><?= esc($auditInstitution['name']) ?> <span class="font-mono text-xs">(<?= esc($auditInstitution['code']) ?>)</span></p>

<?= view('pages/admin/partials/institution-tabs', ['tabInstitution' => $auditInstitution, 'tabActive' => 'audit']) ?>

<p class="mt-4 text-sm text-[#646464]">
    The latest <?= esc((string) count($auditRows)) ?> of <?= esc(number_format($auditTotal)) ?> entries.
    <a class="font-semibold text-brand hover:underline" href="<?= site_url('admin/audit-log?institution=' . $auditInstitution['id']) ?>">Open all of them in the audit log</a>
</p>

<div class="mt-2 overflow-x-auto rounded-lg bg-white shadow">
    <table class="min-w-full divide-y divide-gray-200 text-sm">
        <thead class="bg-gray-50">
            <tr>
                <th class="<?= $th ?>">When</th>
                <th class="<?= $th ?>">Who</th>
                <th class="<?= $th ?>">Role</th>
                <th class="<?= $th ?>">Action</th>
                <th class="<?= $th ?>">Target</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-gray-100">
            <?php if ($auditRows === []): ?>
                <tr><td class="px-3 py-8 text-center text-[#646464]" colspan="5">Nothing has been recorded for this institution yet.</td></tr>
            <?php endif; ?>
            <?php foreach ($auditRows as $row): ?>
                <tr>
                    <td class="whitespace-nowrap px-3 py-2 text-gray-700"><?= esc($row['created_at']) ?></td>
                    <td class="px-3 py-2"><?= esc($row['actor_label'] ?? 'system') ?></td>
                    <td class="px-3 py-2"><?= esc($row['acting_role'] ?? '—') ?></td>
                    <td class="px-3 py-2 font-mono text-xs"><?= esc($row['action']) ?></td>
                    <td class="px-3 py-2"><?= esc(trim(($row['target_type'] ?? '') . ' ' . ($row['target_id'] ?? '')) ?: '—') ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

</div>

<?= $this->endSection() ?>
