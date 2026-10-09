<?= $this->extend('layouts/admin') ?>

<?= $this->section('content') ?>

<h1 class="text-2xl font-bold text-[#0040A6]">System Administration</h1>
<p class="mt-2 text-gray-600">Platform-wide management for every institution.</p>

<ul class="mt-6 flex flex-col gap-2">
    <li><a class="font-semibold text-[#0040A6] hover:underline" href="<?= site_url('admin/audit-log') ?>">Audit log</a></li>
</ul>

<?= $this->endSection() ?>
