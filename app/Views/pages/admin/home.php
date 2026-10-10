<?= $this->extend('layouts/admin') ?>

<?= $this->section('content') ?>

<h1 class="text-2xl font-bold text-[#0040A6]">System Administration</h1>
<p class="mt-2 text-gray-600">Platform-wide management for every institution.</p>

<ul class="mt-6 flex flex-col gap-2">
    <li><a class="font-semibold text-[#0040A6] hover:underline" href="<?= site_url('admin/audit-log') ?>">Audit log</a></li>
    <li><a class="font-semibold text-[#0040A6] hover:underline" href="<?= site_url('admin/institutions') ?>">Institutions</a></li>
    <li><a class="font-semibold text-[#0040A6] hover:underline" href="<?= site_url('admin/users') ?>">Users</a></li>
    <li><a class="font-semibold text-[#0040A6] hover:underline" href="<?= site_url('admin/archive') ?>">Archive</a></li>
    <li><a class="font-semibold text-[#0040A6] hover:underline" href="<?= site_url('admin/monitoring') ?>">System status</a></li>
    <li><a class="font-semibold text-[#0040A6] hover:underline" href="<?= site_url('admin/backups') ?>">Backups</a></li>
    <li><a class="font-semibold text-[#0040A6] hover:underline" href="<?= site_url('admin/plans') ?>">Plans</a></li>
    <li><a class="font-semibold text-[#0040A6] hover:underline" href="<?= site_url('admin/flags') ?>">Feature flags</a></li>
    <li><a class="font-semibold text-[#0040A6] hover:underline" href="<?= site_url('admin/roles') ?>">Roles and permissions</a></li>
    <li><a class="font-semibold text-[#0040A6] hover:underline" href="<?= site_url('admin/settings/general') ?>">General settings</a></li>
    <li><a class="font-semibold text-[#0040A6] hover:underline" href="<?= site_url('admin/settings/security') ?>">Security settings</a></li>
</ul>

<?= $this->endSection() ?>
