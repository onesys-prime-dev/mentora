<?php
/**
 * The dean's home page. Quick access across the top, then a rail with the dean's card and the college
 * in numbers, and the main column: what needs attention, with the chairperson activity and the college
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

$college = $overview['college']['name'] ?? 'Your college';

// Below lg the page is split into phone tabs. Not when the numbers failed to load: the error then stands alone
// with the dean's card and the strip, so no tab can hide it.
$phoneTabs = $loadError ? [] : [
    ['key' => 'attention', 'label' => 'Attention', 'dot' => ($overview['attention'] ?? []) !== []],
    ['key' => 'college',   'label' => 'College',   'dot' => false],
    ['key' => 'updates',   'label' => 'Updates',   'dot' => false],
];

/** The phone-tab panel attributes for a tab key ('' when there are no tabs). */
$panel = static fn (string $key): string => $phoneTabs === [] ? '' : ' data-phone-panel="' . $key . '"' . ($key === $phoneTabs[0]['key'] ? ' data-phone-active' : '');
?>

<?= $this->extend('layouts/app') ?>

<?= $this->section('content') ?>

<div class="flex flex-col gap-5 lg:h-full lg:min-h-0">

    <h1 class="sr-only">Dean dashboard, <?= esc($college) ?></h1>

    <?php if ($phoneTabs !== []): ?>
        <?= view('components/phone-tabs', ['label' => 'Dashboard sections', 'tabs' => $phoneTabs], ['saveData' => false]) ?>
    <?php endif; ?>

    <div<?= $panel('college') ?>><?= $widget('chairperson/quick-access', []) ?></div>

    <!--
        Below lg the rail and the main column dissolve (max-lg:contents) into one stack, so a card in a hidden
        tab leaves no gap; the cards size to their content (capped in their partials).
    -->
    <div class="flex max-lg:flex-col gap-5 lg:flex-1 lg:min-h-0">

        <!-- Identity rail: the dean and the college in numbers. -->
        <div class="w-full lg:w-80 shrink-0 flex flex-col gap-5 lg:min-h-0 max-lg:contents">
            <div class="shrink-0"<?= $panel('college') ?>><?= $widget('chairperson/profile-card', ['first_name' => $profile['first_name'] ?? '', 'last_name' => $profile['last_name'] ?? '', 'program_name' => $college, 'role_label' => 'DEAN']) ?></div>

            <?php if ($overview !== null): ?>
                <div class="shrink-0"<?= $panel('college') ?>><?= $widget('dean/college-snapshot', ['name' => $college] + $overview['snapshot']) ?></div>
            <?php endif; ?>
        </div>

        <!-- Main column. -->
        <div class="flex-1 min-w-0 flex flex-col gap-5 lg:min-h-0 max-lg:contents">

            <?php if ($loadError): ?>

                <div class="flex flex-1 flex-col items-center justify-center gap-3 rounded-3xl border border-[#C33939]/30 bg-white px-8 py-14 text-center" role="alert" data-load-error>
                    <?= icon('warning', 'h-10 w-10 text-[#C33939]') ?>
                    <span class="text-sm font-semibold text-[#1A1C1E]">We could not load your college's numbers</span>
                    <span class="text-xs text-[#646464]">Something went wrong on our side. Nothing has been lost.</span>
                    <a href="<?= esc(site_url('dean/dashboard'), 'attr') ?>" class="inline-flex items-center justify-center pointer-coarse:min-h-11 rounded-full bg-brand px-5 py-2 text-sm font-semibold text-white transition hover:bg-brand-dark focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/55 focus-visible:ring-offset-2">Try again</a>
                </div>

            <?php else: ?>

                <div class="lg:flex-3 lg:min-h-0"<?= $panel('attention') ?>><?= $widget('dean/needs-attention', ['items' => $overview['attention']]) ?></div>

                <?php // Both cards are Updates, so the pair is one panel (side by side from md). ?>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-5 lg:flex-2 lg:min-h-0"<?= $panel('updates') ?>>
                    <div class="min-w-0 lg:min-h-0"><?= $widget('dean/chairperson-activity', $overview['chairperson_activity']) ?></div>
                    <div class="min-w-0 lg:min-h-0"><?= $widget('dean/announcements', ['posts' => $overview['announcements']]) ?></div>
                </div>

            <?php endif; ?>

        </div>

    </div>

</div>

<?= $this->endSection() ?>
