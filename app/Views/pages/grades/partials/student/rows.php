<?php
/**
 * The term's courses, one row each: code, section and title; units, instructors and when the grade last moved;
 * the grade (the band large, the percentage beside it), the status chip and the standing under it. A row whose
 * grade is published (and not withdrawn) has a disclosure button that opens its small breakdown in the empty slot
 * under it; the page script fetches it from data-breakdown-base/{offering}. Everything here reads without script.
 *
 * The course's name links to the course, a link of its own beside the disclosure, on every row but a withdrawn one
 * (the course routes refuse a withdrawn enrolment). Where the links go is the caller's: the student's own views, or
 * the parent's views of their child. With a reportBase, a finalized row also offers its grade report.
 *
 * Status and standing are words; their colour and icon only repeat them.
 *
 * @var list<array<string, mixed>> $rows     StudentGradesOverview::courses(), plus changed and updated (relative time)
 * @var string|null                $area          "parent" words the empty state for a parent; anything else is the student's
 * @var bool                       $hasTerms     whether the student has any term at all (the empty state's wording)
 * @var string                     $courseBase    the course links' prefix: {courseBase}/{offering}/{slug}
 * @var string                     $breakdownBase the breakdowns' address, without the offering
 * @var string|null                $reportBase    the reports' prefix ({reportBase}/{offering}/grades/report/mine); null for none
 */
$chips = [
    'not_published' => ['label' => 'Not published yet', 'tone' => 'neutral', 'icon' => 'clock'],
    'in_progress'   => ['label' => 'In progress', 'tone' => 'info', 'icon' => 'in-progress'],
    'finalized'     => ['label' => 'Finalized', 'tone' => 'success', 'icon' => 'check'],
    'signed_off'    => ['label' => 'Signed off', 'tone' => 'success', 'icon' => 'lock'],
    'withdrawn'     => ['label' => 'Withdrawn', 'tone' => 'neutral', 'icon' => null],
];

$ink = [
    'success' => 'text-[#2B7549]',
    'warning' => 'text-[#946200]',
    'danger'  => 'text-[#C33939]',
    'neutral' => 'text-[#646464]',
];

$percent = static fn (?float $value): ?string => $value === null ? null : number_format($value, 2) . '%';
?>

<section class="flex flex-col gap-3" aria-labelledby="grades-courses">

    <h2 id="grades-courses" class="sr-only">Courses</h2>

    <?php if ($rows === []): ?>

        <?php $forParent = ($area ?? 'student') === 'parent'; ?>
        <?= view('components/empty-state', [
            'icon'  => 'grades',
            'title' => $forParent ? 'No courses to show' : (($hasTerms ?? false) ? 'No courses this term' : 'No courses yet'),
            'text'  => $forParent ? 'When your child is enrolled in a course, its grade shows here.' : 'When you are enrolled in a course, its grade shows here.',
        ], ['saveData' => false]) ?>

    <?php else: ?>

        <ul class="flex flex-col gap-3" data-grades-rows data-breakdown-base="<?= esc($breakdownBase, 'attr') ?>">

            <?php foreach ($rows as $row): ?>
                <?php
                $id         = (int) $row['offering_id'];
                $name       = $row['course_code'] . '-' . $row['section_code'];
                $expandable = $row['published'] && ! $row['withdrawn'];
                $graded     = $row['published'] && ! $row['withdrawn'];

                // The large figure: the band, else the percentage alone (a course with no scale), else a dash.
                $main  = $graded ? ($row['band'] ?? $percent($row['percentage'])) : null;
                $aside = $graded && $row['band'] !== null ? $percent($row['percentage']) : null;

                $units = grade_number($row['units']) . ' ' . ((float) $row['units'] === 1.0 ? 'unit' : 'units');
                $meta  = array_filter([$units, $row['instructors'], $row['updated'] !== '' ? 'Updated ' . $row['updated'] : '']);

                // The standing under the chip; nothing for a course with no grade to stand on yet.
                $tag = null;

                if ($graded && ! ($row['tag'] === 'Incomplete' && $row['percentage'] === null)) {
                    $tag = $row['tag'] === 'INC'
                        ? 'INC' . ($row['inc_until'] !== null ? ' · finish by ' . date('M j, Y', strtotime($row['inc_until'])) : '')
                        : $row['tag'];
                }

                $look = grade_tag_look($row['tag']);
                $chip = $chips[$row['status']];
                ?>

                <li class="overflow-hidden rounded-2xl border border-[#E3E6EE] bg-white transition has-[[data-disclosure][aria-expanded=true]]:border-brand"
                    data-grades-row data-offering-id="<?= $id ?>" data-expandable="<?= $expandable ? '1' : '0' ?>">

                    <div class="flex items-start gap-3 p-4 sm:items-center sm:gap-6 sm:px-5">

                        <div class="flex min-w-0 flex-1 flex-col gap-3 sm:flex-row sm:items-center sm:gap-6">

                            <div class="min-w-0 flex-1">
                                <div class="flex flex-wrap items-center gap-x-2 gap-y-1">
                                    <h3 class="text-sm font-bold text-[#1A1C1E] sm:text-base">
                                        <?php if (! $row['withdrawn']): ?>
                                            <a href="<?= esc($courseBase . '/' . $id . '/' . $row['slug'], 'attr') ?>" class="rounded hover:text-brand hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/55 focus-visible:ring-offset-2" data-course-link><?= esc($name) ?> · <?= esc($row['course_title']) ?></a>
                                        <?php else: ?>
                                            <?= esc($name) ?> · <?= esc($row['course_title']) ?>
                                        <?php endif; ?>
                                    </h3>
                                    <?php if ($row['changed']): ?>
                                        <span class="rounded-full bg-[#FFF1D6] px-2.5 py-0.5 text-xs font-bold text-[#946200]" data-changed>Changed</span>
                                    <?php endif; ?>
                                </div>
                                <p class="mt-1 text-xs text-[#646464]"><?= esc(implode(' · ', $meta)) ?></p>
                                <?php if (($reportBase ?? null) !== null && $row['finalized'] && ! $row['withdrawn']): ?>
                                    <a href="<?= esc($reportBase . '/' . $id . '/grades/report/mine', 'attr') ?>" class="mt-2 inline-flex items-center gap-1.5 rounded text-xs font-semibold text-brand hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/55 focus-visible:ring-offset-2" data-report-link><?= icon('grades', 'h-3.5 w-3.5 text-current') ?>Download report</a>
                                <?php endif; ?>
                            </div>

                            <div class="flex items-center justify-between gap-4 sm:contents">

                                <div class="flex items-baseline gap-2 sm:w-28 sm:flex-col sm:items-end sm:gap-0.5">
                                    <span class="sr-only">Grade:</span>
                                    <span class="text-2xl font-black leading-none <?= $main === null ? 'text-[#A9A9A9]' : 'text-brand' ?>" data-grade-main><?= esc($main ?? '—') ?></span>
                                    <?php if ($aside !== null): ?>
                                        <span class="text-xs font-semibold text-[#646464]" data-grade-percentage><?= esc($aside) ?></span>
                                    <?php endif; ?>
                                </div>

                                <div class="flex flex-col items-end gap-1.5 sm:w-44 sm:items-center">
                                    <span class="whitespace-nowrap" data-grade-status><?= view('components/status-badge', $chip, ['saveData' => false]) ?></span>
                                    <?php if ($tag !== null): ?>
                                        <span class="inline-flex items-center gap-1 text-xs font-semibold <?= $ink[$look['tone']] ?? $ink['neutral'] ?>" data-grade-tag><?php if ($look['icon'] !== null): ?><?= icon($look['icon'], 'h-3.5 w-3.5 text-current') ?><?php endif; ?><?= esc($tag) ?></span>
                                    <?php endif; ?>
                                </div>

                            </div>

                        </div>

                        <?php if ($expandable): ?>
                            <button type="button" data-disclosure aria-expanded="false" aria-controls="grades-breakdown-<?= $id ?>"
                                class="group flex h-10 w-10 shrink-0 items-center justify-center rounded-full text-brand transition hover:bg-brand-tint focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/55 aria-expanded:bg-brand-tint">
                                <span class="sr-only">Grade details for <?= esc($name) ?></span>
                                <svg class="h-5 w-5 transition-transform group-aria-expanded:rotate-180" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 9l6 6 6-6" /></svg>
                            </button>
                        <?php else: ?>
                            <?php // Keeps the columns of every row in line. ?>
                            <span class="hidden h-10 w-10 shrink-0 sm:block" aria-hidden="true"></span>
                        <?php endif; ?>

                    </div>

                    <div id="grades-breakdown-<?= $id ?>" class="border-t border-[#E3E6EE] px-4 py-4 sm:px-5" data-breakdown-slot hidden></div>

                </li>

            <?php endforeach; ?>

        </ul>

    <?php endif; ?>

</section>
