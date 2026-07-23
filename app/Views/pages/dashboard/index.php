<?php
/**
 * Renders a widget partial only if the service actually produced
 * data for this slug (i.e. the current user's role has access to it).
 *
 * Note: CodeIgniter's $this->include($view, $options) does NOT accept
 * arbitrary data via $options — that array is only for cache settings.
 * setVar() is the correct way to inject a variable into the partial.
 */
$renderWidget = function (string $slug, string $partial) use ($widgets) {
    if (! isset($widgets[$slug])) {
        return '';
    }

    $this->setVar('data', $widgets[$slug]);

    return $this->include($partial);
};
?>

<?= $this->extend('layouts/app') ?>

<?= $this->section('content') ?>

<div class="flex flex-col gap-10">

    <div class="flex justify-between items-center">

        <div class="flex items-center gap-3 text-3xl font-display">
            <span class="font-bold">
                A.Y <?= $academic_term['academic_year'] ?>
            </span>
            <span class="">-</span>
            <span class="">
                <?= $academic_term['name'] ?>
            </span>
        </div>

        <?= $this->include('components/search') ?>

    </div>

    <div class="flex flex-col gap-10">

        <div class="flex gap-10">

            <?= $renderWidget('profile-card', 'pages/dashboard/partials/profile-card') ?>

            <?= $renderWidget('key-metrics', 'pages/dashboard/partials/key-metrics') ?>

            <?= $renderWidget('upcoming-tasks', 'pages/dashboard/partials/upcoming-tasks') ?>

        </div>

        <div class="flex gap-10">

            <?= $renderWidget('announcements', 'pages/dashboard/partials/announcements') ?>

            <?= $renderWidget('reminders', 'pages/dashboard/partials/reminders') ?>

            <?= $renderWidget('quick-access', 'pages/dashboard/partials/quick-access') ?>

        </div>

    </div>

</div>

<?= $this->endSection() ?>