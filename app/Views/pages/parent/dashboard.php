<?php
/**
 * The parent's landing page until the parent dashboard is built: the linked
 * children by name.
 *
 * @var list<array{student_id: int, name: string, institution_id: int}> $children
 */
?>

<?= $this->extend('layouts/app') ?>

<?= $this->section('content') ?>

<div class="flex flex-col gap-8 py-3">

    <?= view('components/page-head', [
        'title'       => 'Parent',
        'icon'        => 'user',
        'description' => 'Your children\'s progress at a glance.',
    ], ['saveData' => false]) ?>

    <section class="flex flex-col gap-3 rounded-2xl bg-white p-6 shadow-sm" aria-labelledby="parent-children">
        <h2 id="parent-children" class="text-sm font-bold text-brand">Your children</h2>

        <ul class="flex flex-col divide-y divide-gray-100">
            <?php foreach ($children as $child): ?>
                <li class="flex items-center justify-between gap-3 py-2">
                    <span class="text-sm font-semibold text-[#1A1C1E]"><?= esc($child['name']) ?></span>
                    <span class="text-xs text-[#646464]">Student</span>
                </li>
            <?php endforeach; ?>
        </ul>

        <p class="text-xs text-[#646464]">Their courses and grades are coming to this page soon.</p>
    </section>

</div>

<?= $this->endSection() ?>
