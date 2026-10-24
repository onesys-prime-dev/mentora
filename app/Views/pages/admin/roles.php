<?php
/**
 * Role x permission matrix. Every cell is its own small form, so it works
 * without JavaScript: pressing a cell asks the server to flip that one
 * permission for that one role.
 *
 * @var array<string, string>       $roleTitles    role => display name
 * @var string                      $lockedRole
 * @var array<string, string>       $platformPerms permission => description
 * @var array<string, string>       $academicPerms permission => description
 * @var array<string, list<string>> $currentMatrix
 * @var array<string, list<string>> $defaultMatrix
 */
$sections = [
    'Platform' => $platformPerms,
    'Inside an institution' => $academicPerms,
];

?>

<?= $this->extend('layouts/admin') ?>

<?= $this->section('content') ?>

<?= view('components/page-head', ['title' => 'Roles and permissions', 'icon' => 'lock'], ['saveData' => false]) ?>
<p class="mt-2 max-w-3xl text-sm text-[#646464]">
    What each role may do. Changes apply on the next request and need your password. The system administrator role is locked,
    and every role keeps at least one permission. <span class="font-semibold text-amber-700">Edited</span> marks a cell that differs from the default.
</p>

<?php if (session()->has('message')): ?>
    <div class="mt-4"><?= view('components/alert', ['tone' => 'success', 'text' => (string) session('message')], ['saveData' => false]) ?></div>
<?php endif; ?>
<?php if (session()->has('error')): ?>
    <div class="mt-4"><?= view('components/alert', ['tone' => 'danger', 'text' => (string) session('error')], ['saveData' => false]) ?></div>
<?php endif; ?>

<?= view('components/permission-grid', [
    'roleTitles' => $roleTitles, 'sections' => $sections, 'lockedRole' => $lockedRole,
    'currentMatrix' => $currentMatrix, 'defaultMatrix' => $defaultMatrix, 'gridAction' => site_url('admin/roles/permissions'),
], ['saveData' => false]) ?>

<?= $this->endSection() ?>
