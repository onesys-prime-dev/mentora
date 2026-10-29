<?php
/**
 * The dean's home page. Quick access across the top, then a rail with the dean's card and the department
 * in numbers, and the main column: what needs attention, with the chairperson activity and the department
 * announcements beside each other below it. The cards are the chairperson dashboard's flat cards (its
 * profile card and quick-access strip are reused as they are); the rest are the dean's own partials.
 *
 * @var array<string, mixed>|null $overview
 * @var array<string, mixed> $profile
 * @var bool $loadError
 */
$widget = function (string $partial, array $data): string {
    $this->setVar('data', $data);

    return $this->include('pages/dashboard/partials/' . $partial);
};

$department = $overview['department']['name'] ?? 'Your department';
?>

<?= $this->extend('layouts/app') ?>

<?= $this->section('content') ?>

<div class="flex flex-col gap-5 lg:h-full lg:min-h-0">

    <h1 class="sr-only">Dean dashboard, <?= esc($department) ?></h1>

    <?= $widget('chairperson/quick-access', []) ?>

    <div class="flex flex-wrap lg:flex-nowrap gap-5 lg:flex-1 lg:min-h-0">

        <!-- Identity rail: the dean and the department in numbers. -->
        <div class="w-full lg:w-80 shrink-0 flex flex-col gap-5 lg:min-h-0">
            <div class="shrink-0"><?= $widget('chairperson/profile-card', ['first_name' => $profile['first_name'] ?? '', 'last_name' => $profile['last_name'] ?? '', 'program_name' => $department, 'role_label' => 'DEAN']) ?></div>

            <?php if ($overview !== null): ?>
                <div class="shrink-0"><?= $widget('dean/department-snapshot', ['name' => $department] + $overview['snapshot']) ?></div>
            <?php endif; ?>
        </div>

        <!-- Main column. -->
        <div class="flex-1 min-w-0 flex flex-col gap-5 lg:min-h-0">

            <?php if ($loadError): ?>

                <div class="flex flex-1 flex-col items-center justify-center gap-3 rounded-3xl border border-[#C33939]/30 bg-white px-8 py-14 text-center" role="alert" data-load-error>
                    <?= icon('warning', 'h-10 w-10 text-[#C33939]') ?>
                    <span class="text-sm font-semibold text-[#1A1C1E]">We could not load your department's numbers</span>
                    <span class="text-xs text-[#646464]">Something went wrong on our side. Nothing has been lost.</span>
                    <a href="<?= esc(site_url('dean/dashboard'), 'attr') ?>" class="rounded-full bg-brand px-5 py-2 text-sm font-semibold text-white transition hover:bg-brand-dark focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/55 focus-visible:ring-offset-2">Try again</a>
                </div>

            <?php else: ?>

                <div class="h-80 lg:h-auto lg:flex-3 lg:min-h-0"><?= $widget('dean/needs-attention', ['items' => $overview['attention']]) ?></div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-5 lg:flex-2 lg:min-h-0">
                    <div class="h-80 lg:h-auto min-w-0 lg:min-h-0"><?= $widget('dean/chairperson-activity', $overview['chairperson_activity']) ?></div>
                    <div class="h-80 lg:h-auto min-w-0 lg:min-h-0"><?= $widget('dean/announcements', ['posts' => $overview['announcements']]) ?></div>
                </div>

            <?php endif; ?>

        </div>

    </div>

</div>

<?= $this->endSection() ?>
