<?php
/**
 * The institution's "Roles and permissions": what each of its roles may do,
 * on top of the platform's settings (audit decisions U1a–U1d). The same grid
 * as the system administrator's page; "Edited" marks a box that differs from
 * the platform's.
 *
 * @var array<string, string>       $roleTitles    role => display name
 * @var array<string, string>       $rolePerms     permission => description (academic only)
 * @var array<string, list<string>> $currentMatrix this institution's
 * @var array<string, list<string>> $defaultMatrix the platform's
 * @var bool                        $rolesCanEdit  false while the institution is read-only
 */
?>

<?= $this->extend('layouts/app') ?>

<?= $this->section('content') ?>

<div class="flex flex-col gap-6 py-3">

    <?= view('components/page-head', [
        'title'       => 'Roles and permissions',
        'icon'        => 'lock',
        'description' => 'What each role may do in your institution. A box you have not changed follows the platform\'s setting. Changes apply on the next click and need your password; every role keeps at least one permission.',
        'backUrl'     => site_url('institution/dashboard'),
        'backLabel'   => 'Back to home',
    ], ['saveData' => false]) ?>

    <?php if (! $rolesCanEdit): ?>
        <?= view('components/lock-note', ['kind' => 'read_only'], ['saveData' => false]) ?>
    <?php endif; ?>

    <?= view('components/permission-grid', [
        'roleTitles'    => $roleTitles,
        'sections'      => ['Inside your institution' => $rolePerms],
        'lockedRole'    => '',
        'gridReadOnly'  => ! $rolesCanEdit,
        'currentMatrix' => $currentMatrix,
        'defaultMatrix' => $defaultMatrix,
        'gridAction'    => site_url('institution/roles/permissions'),
    ], ['saveData' => false]) ?>

</div>

<?= $this->endSection() ?>
