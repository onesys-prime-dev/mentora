<?php
/**
 * "Confirm your password": the step in front of a dangerous action.
 *
 * The system administrator's pages keep the admin layout; everyone else gets
 * the app shell and the shared components.
 *
 * @var bool   $reauthAdmin
 * @var string $reauthPost  where the form posts
 */
?>
<?= $this->extend($reauthAdmin ? 'layouts/admin' : 'layouts/app') ?>

<?= $this->section('content') ?>

<?php if ($reauthAdmin): ?>

    <div class="mx-auto max-w-md rounded-lg bg-white p-6 shadow">
        <h1 class="text-xl font-bold text-[#0040A6]">Confirm your password</h1>
        <p class="mt-2 text-sm text-gray-600">This action needs your password again. It stays confirmed for a few minutes.</p>

        <?php if (session()->has('error')): ?>
            <p class="mt-4 rounded bg-red-50 px-3 py-2 text-sm text-red-700" role="alert"><?= esc((string) session('error')) ?></p>
        <?php endif; ?>

        <form class="mt-4 flex flex-col gap-3" action="<?= esc($reauthPost) ?>" method="post">
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

<?php else: ?>

    <div class="mx-auto flex max-w-md flex-col gap-6 py-3">

        <?= view('components/page-head', [
            'title'       => 'Confirm your password',
            'icon'        => 'lock',
            'description' => 'This action needs your password again. It stays confirmed for a few minutes.',
        ], ['saveData' => false]) ?>

        <?php if (session()->has('error')): ?>
            <?= view('components/alert', ['tone' => 'danger', 'text' => (string) session('error')], ['saveData' => false]) ?>
        <?php endif; ?>

        <form class="flex flex-col gap-4 rounded-2xl bg-white p-6 shadow-sm" action="<?= esc($reauthPost) ?>" method="post" data-reauth-page>
            <?= csrf_field() ?>
            <?= view('components/form-field', [
                'name'     => 'password',
                'label'    => 'Password',
                'type'     => 'password',
                'required' => true,
                'attrs'    => 'autocomplete="current-password" autofocus',
            ], ['saveData' => false]) ?>
            <div>
                <button type="submit" class="rounded-full bg-[#0040A6] px-5 py-2 text-sm font-semibold text-white transition hover:bg-[#002068] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#0040A6]/50">Confirm</button>
            </div>
        </form>

    </div>

<?php endif; ?>

<?= $this->endSection() ?>
