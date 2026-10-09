<?= $this->extend('layouts/admin') ?>

<?= $this->section('content') ?>

<div class="mx-auto max-w-md rounded-lg bg-white p-6 shadow">
    <h1 class="text-xl font-bold text-[#0040A6]">Confirm your password</h1>
    <p class="mt-2 text-sm text-gray-600">This action needs your password again. It stays confirmed for a few minutes.</p>

    <?php if (session()->has('error')): ?>
        <p class="mt-4 rounded bg-red-50 px-3 py-2 text-sm text-red-700" role="alert"><?= esc((string) session('error')) ?></p>
    <?php endif; ?>

    <form class="mt-4 flex flex-col gap-3" action="<?= site_url('admin/reauth') ?>" method="post">
        <?= csrf_field() ?>
        <label class="text-sm font-medium text-gray-700" for="password">Password</label>
        <input
            id="password"
            name="password"
            type="password"
            autocomplete="current-password"
            required
            autofocus
            class="rounded border border-gray-300 px-3 py-2 focus:outline-none focus:ring-2 focus:ring-[#0040A6]/55">
        <button type="submit" class="rounded bg-[#0040A6] px-4 py-2 font-semibold text-white hover:bg-[#002068]">Confirm</button>
    </form>
</div>

<?= $this->endSection() ?>
