<?php
/**
 * Everything that reaches this person, at four levels and always in this order: the platform's, the
 * institution's, their department's and their courses'. A level with nothing to say still has its place.
 *
 * @var string $area
 * @var string $platform
 * @var string|null $institution
 * @var list<array<string, mixed>> $department
 * @var list<array<string, mixed>> $courses
 */
$when = static fn (string $at): string => date('M j, Y', strtotime($at));

$section = static function (string $id, string $title, string $hint): string {
    return '<header class="flex flex-col gap-0.5"><h2 id="' . esc($id, 'attr') . '" class="text-base font-bold text-[#1A1C1E]">' . esc($title) . '</h2><p class="text-xs text-[#646464]">' . esc($hint) . '</p></header>';
};

$quiet = static fn (string $text): string => '<p class="rounded-xl border border-dashed border-gray-200 px-4 py-5 text-sm text-[#646464]">' . esc($text) . '</p>';
?>

<?= $this->extend('layouts/app') ?>

<?= $this->section('content') ?>

<div class="flex flex-col gap-8 py-3">

    <?= view('components/page-head', [
        'title'       => 'Announcements',
        'icon'        => 'announcement',
        'description' => 'Everything that reaches you, from the whole platform down to your own courses.',
        'backUrl'     => site_url($area . '/dashboard'),
        'backLabel'   => 'Back to dashboard',
    ], ['saveData' => false]) ?>

    <?php if ($loadError): ?>

        <div class="flex flex-col items-center justify-center gap-3 rounded-2xl border border-[#C33939]/30 bg-white px-8 py-14 text-center" role="alert" data-load-error>
            <?= icon('warning', 'h-10 w-10 text-[#C33939]') ?>
            <span class="text-sm font-semibold text-[#1A1C1E]">We could not load your announcements</span>
            <span class="text-xs text-[#646464]">Something went wrong on our side. Nothing has been lost.</span>
            <a href="<?= esc(site_url($area . '/announcements')) ?>" class="rounded-full bg-brand px-5 py-2 text-sm font-semibold text-white transition hover:bg-brand-dark focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/55 focus-visible:ring-offset-2">Try again</a>
        </div>

    <?php else: ?>

        <section class="flex flex-col gap-3" aria-labelledby="level-platform" data-level="platform">
            <?= $section('level-platform', 'Platform', 'From the Mentora team, for everyone.') ?>
            <?= $platform !== '' ? '<p class="rounded-2xl bg-brand px-5 py-4 text-sm text-white">' . esc($platform) . '</p>' : $quiet('Nothing from the platform right now.') ?>
        </section>

        <section class="flex flex-col gap-3" aria-labelledby="level-institution" data-level="institution">
            <?= $section('level-institution', 'Your institution', 'From your institution\'s administrators.') ?>
            <?= $institution !== null ? '<p class="rounded-2xl bg-brand-tint px-5 py-4 text-sm text-brand-dark">' . esc($institution) . '</p>' : $quiet('Nothing from your institution right now.') ?>
        </section>

        <section class="flex flex-col gap-3" aria-labelledby="level-department" data-level="department">
            <?= $section('level-department', 'Your department', 'From the dean, for the department, sub-department or program you belong to.') ?>

            <?php if ($department === []): ?>
                <?= $quiet('Nothing from your department right now.') ?>
            <?php else: ?>
                <ul class="flex flex-col gap-3">
                    <?php foreach ($department as $a): ?>
                        <li class="flex flex-col gap-1 rounded-2xl bg-white px-5 py-4 shadow-sm" data-department-announcement>
                            <span class="text-sm font-bold text-[#1A1C1E]"><?= esc($a['title']) ?></span>
                            <p class="whitespace-pre-line text-sm text-[#3D3D3D]"><?= esc($a['message']) ?></p>
                            <span class="text-xs text-[#646464]">
                                <?= esc($a['author'] !== '' ? $a['author'] . ' · ' : '') ?><?= esc($a['scope_label']) ?> · <?= esc($when($a['created_at'])) ?><?= $a['ends_on'] ? ' · until ' . esc($when($a['ends_on'])) : '' ?>
                            </span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </section>

        <section class="flex flex-col gap-3" aria-labelledby="level-course" data-level="course">
            <?= $section('level-course', 'Your courses', 'The latest posts in the courses you are in.') ?>

            <?php if ($courses === []): ?>
                <?= $quiet('No posts in your courses yet.') ?>
            <?php else: ?>
                <ul class="flex flex-col divide-y divide-gray-100 rounded-2xl bg-white shadow-sm">
                    <?php foreach ($courses as $c): ?>
                        <li>
                            <a href="<?= esc($c['url']) ?>" class="flex flex-col gap-0.5 px-5 py-3 transition hover:bg-gray-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/50" data-course-announcement>
                                <span class="text-sm font-semibold text-[#1A1C1E]"><?= esc($c['subject']) ?></span>
                                <span class="text-xs text-[#646464]"><?= esc($c['code']) ?> · <?= esc($c['author']) ?> · <?= esc($when($c['created_at'])) ?></span>
                            </a>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </section>

    <?php endif; ?>

</div>

<?= $this->endSection() ?>
