<?php
/**
 * Invite a person. The account is made when they use the emailed link and
 * choose a password: nobody but them ever knows it.
 *
 * @var list<array<string, mixed>> $newInstitutions institutions that have finished onboarding
 * @var list<string>               $newRoles        the roles that can be given here
 * @var array<string, mixed>       $newValues       what was typed before a refusal
 */
use App\Services\Users\UserFilters;

$primary = 'rounded-full bg-brand px-5 py-2 text-sm font-semibold text-white transition hover:bg-brand-dark focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/50';
$cancel  = 'rounded-full border border-gray-300 bg-white px-5 py-2 text-sm font-semibold text-[#3D3D3D] transition hover:bg-gray-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/50';

$institutionOptions = ['' => 'Choose an institution'];

foreach ($newInstitutions as $institution) {
    $institutionOptions[(string) $institution['id']] = (string) $institution['name'];
}

$roleOptions = ['' => 'Choose a role'];

foreach ($newRoles as $role) {
    $roleOptions[$role] = UserFilters::ROLE_LABELS[$role] ?? $role;
}
?>

<?= $this->extend('layouts/admin') ?>

<?= $this->section('content') ?>

<div class="rounded-3xl bg-white p-5 shadow-sm sm:p-8" data-page-panel>

<div class="mx-auto max-w-lg">

    <?= view('components/page-head', [
        'title'       => 'Invite a person',
        'icon'        => 'user',
        'description' => 'They get an emailed link, valid for a limited time and usable once, and choose their own password on it. The account is made then.',
        'backUrl'     => site_url('admin/users'),
        'backLabel'   => 'Users',
    ], ['saveData' => false]) ?>

    <p class="mt-2 text-xs text-[#646464]">System administrators are not made here: they are created with <span class="font-mono">php spark admin:create</span>.</p>

    <?php if (session()->has('error')): ?>
        <div class="mt-4"><?= view('components/alert', ['tone' => 'danger', 'text' => (string) session('error')], ['saveData' => false]) ?></div>
    <?php endif; ?>

    <form method="post" action="<?= site_url('admin/users') ?>" class="mt-5 flex flex-col gap-4 rounded-2xl bg-white p-6 shadow-sm">
        <?= csrf_field() ?>

        <?= view('components/form-field', ['name' => 'institution_id', 'label' => 'Institution', 'type' => 'select', 'options' => $institutionOptions, 'value' => (string) $newValues['institution_id'], 'required' => true], ['saveData' => false]) ?>
        <?= view('components/form-field', ['name' => 'role', 'label' => 'Role', 'type' => 'select', 'options' => $roleOptions, 'value' => (string) $newValues['role'], 'required' => true, 'help' => 'A student takes one of the plan\'s seats.'], ['saveData' => false]) ?>

        <div class="grid gap-4 sm:grid-cols-2">
            <?= view('components/form-field', ['name' => 'first_name', 'label' => 'First name', 'value' => (string) $newValues['first_name'], 'maxlength' => 100, 'required' => true], ['saveData' => false]) ?>
            <?= view('components/form-field', ['name' => 'last_name', 'label' => 'Last name', 'value' => (string) $newValues['last_name'], 'maxlength' => 100, 'required' => true], ['saveData' => false]) ?>
        </div>

        <?= view('components/form-field', ['name' => 'email', 'label' => 'Email address', 'type' => 'email', 'value' => (string) $newValues['email'], 'maxlength' => 254, 'required' => true], ['saveData' => false]) ?>

        <div class="flex flex-wrap items-center justify-end gap-3">
            <a class="<?= $cancel ?>" href="<?= site_url('admin/users') ?>">Cancel</a>
            <button type="submit" class="<?= $primary ?>">Send invitation</button>
        </div>
    </form>
</div>

</div>

<?= $this->endSection() ?>
