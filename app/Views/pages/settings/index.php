<?php
/**
 * /settings: one scrolling page of section cards (D21). Desktop has a sticky jump list beside them;
 * phones get a collapsed "Jump to" menu above stacked cards. Both are drawn from $sections.
 *
 * @var list<array{id: string, label: string, view: string}> $sections
 * @var int $minLength
 * @var array{on: bool, required: bool, email_masked: string} $twoFactor
 */
$errors = (array) (session('errors') ?? []);
$link   = 'block rounded-lg px-3 py-2 text-sm text-[#374151] transition hover:bg-brand-tint hover:text-brand focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/50 pointer-coarse:min-h-11';
?>

<?= $this->extend('layouts/app') ?>

<?= $this->section('content') ?>

<div class="flex flex-col gap-4 py-3">

    <?= view('components/page-head', [
        'title'       => 'Settings',
        'icon'        => 'settings',
        'description' => 'Your password, sign-in and the emails Mentora sends you.',
    ], ['saveData' => false]) ?>

    <div class="flex flex-col gap-4 lg:grid lg:grid-cols-[13rem_minmax(0,1fr)] lg:items-start">

        <details class="rounded-2xl bg-white shadow-sm lg:hidden" data-settings-jump>
            <summary class="flex cursor-pointer list-none items-center justify-between gap-2 rounded-2xl px-4 py-3 text-sm font-semibold text-[#1A1C1E] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/50 pointer-coarse:min-h-11 [&::-webkit-details-marker]:hidden">
                Jump to
                <svg class="h-4 w-4 text-gray-500" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 0 1 1.06.02L10 11.17l3.71-3.94a.75.75 0 1 1 1.08 1.04l-4.25 4.5a.75.75 0 0 1-1.08 0l-4.25-4.5a.75.75 0 0 1 .02-1.06Z" clip-rule="evenodd" /></svg>
            </summary>
            <nav class="border-t border-gray-100 p-2" aria-label="Settings sections">
                <?php foreach ($sections as $s): ?>
                    <a href="#<?= esc($s['id'], 'attr') ?>" class="<?= $link ?>"><?= esc($s['label']) ?></a>
                <?php endforeach; ?>
            </nav>
        </details>

        <nav class="sticky top-20 hidden rounded-2xl bg-white p-2 shadow-sm lg:block" aria-label="Settings sections">
            <?php foreach ($sections as $s): ?>
                <a href="#<?= esc($s['id'], 'attr') ?>" class="<?= $link ?>"><?= esc($s['label']) ?></a>
            <?php endforeach; ?>
        </nav>

        <div class="flex min-w-0 flex-col gap-4">
            <?php foreach ($sections as $s): ?>
                <section id="<?= esc($s['id'], 'attr') ?>" class="flex scroll-mt-20 flex-col gap-3 rounded-2xl bg-white p-4 shadow-sm sm:p-6">
                    <?= view($s['view'], ['minLength' => $minLength, 'errors' => $errors, 'twoFactor' => $twoFactor], ['saveData' => false]) ?>
                </section>
            <?php endforeach; ?>
        </div>

    </div>

</div>

<?= $this->endSection() ?>
