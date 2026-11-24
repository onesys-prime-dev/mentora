<?php
/**
 * People → Requests: official-detail corrections waiting for this administrator (FR7, D22).
 *
 * @var list<array<string, mixed>> $requests
 * @var array<int, array<string, mixed>> $avatars
 * @var bool $canDecide
 * @var bool $readOnly
 * @var int  $peopleOpen open invitations, for the tab's count
 */
?>

<?= $this->extend('layouts/app') ?>

<?= $this->section('content') ?>

<div class="py-3">

<?= view('components/back-link', ['backUrl' => site_url('institution/dashboard'), 'backLabel' => 'Back to home'], ['saveData' => false]) ?>

<div class="flex flex-col gap-6 rounded-3xl bg-white p-5 shadow-sm sm:p-8" data-page-panel>

    <?= view('components/page-head', [
        'title'       => 'Requests',
        'icon'        => 'user',
        'description' => 'Corrections your people asked for. Approving one changes their official details.',
    ], ['saveData' => false]) ?>

    <?php if ($readOnly): ?>
        <?= view('components/lock-note', ['kind' => 'read_only'], ['saveData' => false]) ?>
    <?php endif; ?>

    <?php if (session()->has('message')): ?>
        <?= view('components/alert', ['tone' => 'success', 'text' => (string) session('message')], ['saveData' => false]) ?>
    <?php endif; ?>
    <?php if (session()->has('error')): ?>
        <?= view('components/alert', ['tone' => 'danger', 'text' => (string) session('error')], ['saveData' => false]) ?>
    <?php endif; ?>

    <?= view('components/tabs', ['ariaLabel' => 'People', 'tabs' => [
        ['label' => 'People', 'href' => site_url('institution/people')],
        ['label' => 'Invitations', 'href' => site_url('institution/people') . '?view=invitations', 'count' => $peopleOpen],
        ['label' => 'Parents', 'href' => site_url('institution/people/parents')],
        ['label' => 'Requests', 'href' => site_url('institution/people/requests'), 'active' => true, 'count' => count($requests)],
    ]], ['saveData' => false]) ?>

    <?= view('pages/institution/partials/people-requests', ['requests' => $requests, 'avatars' => $avatars, 'canDecide' => $canDecide, 'base' => 'institution/people/requests'], ['saveData' => false]) ?>

</div>

</div>

<?= $this->endSection() ?>
