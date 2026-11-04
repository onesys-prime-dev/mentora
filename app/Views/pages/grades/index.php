<?php
/**
 * The Grades page: grades across every section the person teaches, or every course the student takes. The
 * Announcements shell (the way back on the background, one white panel, the page head, a brand-tint bar of tabs
 * and pickers), a summary strip, then the list. Every choice is in the query string, so a view can be bookmarked
 * and Back works.
 *
 * The instructor's bar has tabs, and the list is the tab: the Gradebook (section cards and the chosen section's
 * grid), To grade, or At risk. The student's bar has no tabs: the term's line on the left, "Change term" on the
 * right; the list is the term's courses (partials/student).
 *
 * The student's page also gets rows, summary, bar (StudentGradesOverview::barLine) and hasScale, and where its
 * links go (courseBase, breakdownBase, reportBase). The parent's page is the student's for one child (area
 * "parent"): it also gets the children and the child shown (childId), for the "Showing" switcher in the head.
 *
 * @var string                     $area
 * @var string                     $base     this page's address, without a query
 * @var string                     $tab      gradebook, to-grade or at-risk
 * @var list<array<string, mixed>> $terms
 * @var int|null                   $termId
 * @var list<array<string, mixed>> $sections
 * @var array<string, mixed>|null  $selected the section whose grid is shown
 * @var array<string, int>         $counts   what each tab shows beside its name
 * @var string|null                $reason   the At risk reason picked
 * @var list<array<string, mixed>> $queue
 * @var list<string>               $types
 * @var array<string, mixed>       $filters  the To grade filters: qsection, type, late
 * @var bool                       $loadError
 * @var string|null                $backTo   the way back; the area's dashboard when not given (not $backUrl, which page-head would also draw)
 */

// The student's page, for the student or for a parent looking at their child.
$studentShaped = $area === 'student' || $area === 'parent';

// The parent's head carries the existing child switcher, opening the same page for another child.
$actions = $area === 'parent' && count($children ?? []) > 1
    ? view('pages/parent/partials/child-switcher', ['children' => $children, 'selected' => (int) $childId, 'path' => 'grades'], ['saveData' => false])
    : '';

// A link to this page that keeps the term; the rest is what the link is for.
$link = static fn (array $params = []): string => $base . '?' . http_build_query(array_filter(
    ['term' => $termId] + $params,
    static fn ($value): bool => $value !== null && $value !== '' && $value !== false,
));

$select = 'min-h-10 rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-[#1A1C1E] focus:border-brand/60 focus:outline-none focus:ring-2 focus:ring-brand/40';

$tabs = ['gradebook' => 'Gradebook', 'to-grade' => 'To grade', 'at-risk' => 'At risk'];
?>

<?= $this->extend('layouts/app') ?>

<?= $this->section('content') ?>

<div class="py-3">

<?php // The way back stays on the background, above the panel, not in the box. ?>
<?= view('components/back-link', ['backUrl' => $backTo ?? site_url($area . '/dashboard'), 'backLabel' => 'Back to dashboard'], ['saveData' => false]) ?>

<div class="flex flex-col gap-6 rounded-3xl bg-white p-5 shadow-sm sm:p-8" data-page-panel>

    <?= view('components/page-head', [
        'title'       => 'Grades',
        'icon'        => 'grades',
        'description' => match ($area) {
            'student' => 'Your grades in every course, as they stand now.',
            'parent'  => "Your child's grades in every course.",
            default   => 'Every section you teach.',
        },
        'actions'     => $actions,
    ], ['saveData' => false]) ?>

    <?php if ($loadError): ?>

        <div class="flex flex-col items-center justify-center gap-3 rounded-2xl border border-[#C33939]/30 bg-white px-8 py-14 text-center" role="alert" data-load-error>
            <?= icon('warning', 'h-10 w-10 text-[#C33939]') ?>
            <span class="text-sm font-semibold text-[#1A1C1E]"><?= $area === 'parent' ? "We could not load your child's grades" : 'We could not load your grades' ?></span>
            <span class="text-xs text-[#646464]">Something went wrong on our side. Nothing has been lost.</span>
            <a href="<?= esc($studentShaped ? $link() : $link(['tab' => $tab === 'gradebook' ? null : $tab])) ?>" class="rounded-full bg-brand px-5 py-2 text-sm font-semibold text-white transition hover:bg-brand-dark focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/55 focus-visible:ring-offset-2">Try again</a>
        </div>

    <?php elseif ($studentShaped): ?>

        <?php if ($bar !== null): ?>
            <?php
            $plural = static fn (float|int $n, string $word): string => grade_number($n) . ' ' . $word . ((float) $n === 1.0 ? '' : 's');
            $line   = array_filter([$plural($bar['courses'], 'course'), $plural($bar['units'], 'unit'), $bar['ends']]);
            ?>

            <?php // The term's line on the left, "Change term" on the right; no tabs, no filters. ?>
            <div class="flex flex-col gap-3 rounded-2xl border border-brand/20 bg-brand-tint p-3 sm:flex-row sm:items-center sm:justify-between">

                <p class="px-1 text-sm text-brand-dark" data-term-line>
                    <strong class="font-bold"><?= esc($bar['name']) ?></strong> · <?= esc(implode(' · ', $line)) ?>
                </p>

                <?php // The picker reads "Change term"; the term shown is marked in the list. ?>
                <label class="sr-only" for="grades-term">Change term</label>
                <select id="grades-term" data-select-link data-term-select class="<?= $select ?> sm:w-56">
                    <option value="" selected disabled hidden>Change term</option>
                    <?php foreach ($terms as $term): ?>
                        <option value="<?= esc($base . '?term=' . $term['id']) ?>"><?= esc($term['name'] . ' (' . $term['academic_year'] . ')' . ($term['id'] === $termId ? ' · shown' : '')) ?></option>
                    <?php endforeach; ?>
                </select>

            </div>
        <?php endif; ?>

        <?php $data = compact('rows', 'summary', 'hasScale', 'courseBase', 'breakdownBase', 'reportBase', 'area') + ['hasTerms' => $terms !== []]; ?>

        <?php if ($rows !== []): ?>
            <?= view('pages/grades/partials/student/summary', $data, ['saveData' => false]) ?>
        <?php endif; ?>

        <?= view('pages/grades/partials/student/rows', $data, ['saveData' => false]) ?>

    <?php else: ?>

        <div class="flex flex-col gap-3 rounded-2xl border border-brand/20 bg-brand-tint p-3 lg:flex-row lg:items-center lg:justify-between">

            <nav aria-label="Grades" class="flex flex-wrap items-center gap-2" data-grades-tabs>
                <?php foreach ($tabs as $key => $label): ?>
                    <?php $on = $tab === $key; ?>
                    <a href="<?= esc($link(['tab' => $key === 'gradebook' ? null : $key, 'section' => $key === 'gradebook' ? ($selected['offering_id'] ?? null) : null])) ?>"
                       class="rounded-full px-4 py-1.5 text-sm font-semibold transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/55 <?= $on ? 'bg-brand text-white' : 'bg-white text-brand-dark shadow-sm hover:bg-white/70' ?>"
                       data-grades-tab="<?= esc($key) ?>"
                       <?= $on ? 'aria-current="page"' : '' ?>><?= esc($label) ?><?php if (isset($counts[$key])): ?> <span class="ml-1 rounded-full px-2 py-0.5 text-xs font-bold <?= $on ? 'bg-white/25 text-white' : 'bg-brand-tint text-brand-dark' ?>" data-tab-count="<?= esc($key) ?>"><?= (int) $counts[$key] ?></span><?php endif; ?></a>
                <?php endforeach; ?>
            </nav>

            <div class="flex flex-col gap-2 sm:flex-row sm:flex-wrap sm:items-center">

                <?php if ($tab === 'to-grade' && $sections !== []): ?>
                    <label class="sr-only" for="grades-qsection">Section</label>
                    <select id="grades-qsection" data-select-link data-queue-section class="<?= $select ?>">
                        <option value="<?= esc($link(['tab' => 'to-grade', 'type' => $filters['type'], 'late' => $filters['late'] ? 1 : null])) ?>">All sections</option>
                        <?php foreach ($sections as $s): ?>
                            <option value="<?= esc($link(['tab' => 'to-grade', 'qsection' => $s['offering_id'], 'type' => $filters['type'], 'late' => $filters['late'] ? 1 : null])) ?>" <?= $filters['qsection'] === $s['offering_id'] ? 'selected' : '' ?>><?= esc($s['course_code'] . '-' . $s['section_code']) ?></option>
                        <?php endforeach; ?>
                    </select>

                    <label class="sr-only" for="grades-type">Type</label>
                    <select id="grades-type" data-select-link data-queue-type class="<?= $select ?>">
                        <option value="<?= esc($link(['tab' => 'to-grade', 'qsection' => $filters['qsection'], 'late' => $filters['late'] ? 1 : null])) ?>">All types</option>
                        <?php foreach ($types as $type): ?>
                            <option value="<?= esc($link(['tab' => 'to-grade', 'qsection' => $filters['qsection'], 'type' => $type, 'late' => $filters['late'] ? 1 : null])) ?>" <?= $filters['type'] === $type ? 'selected' : '' ?>><?= esc(ucfirst($type)) ?></option>
                        <?php endforeach; ?>
                    </select>

                    <?php // A link that turns the filter on or off; the box and the hidden word say which it is. ?>
                    <a href="<?= esc($link(['tab' => 'to-grade', 'qsection' => $filters['qsection'], 'type' => $filters['type'], 'late' => $filters['late'] ? null : 1])) ?>"
                       class="inline-flex min-h-10 items-center gap-2 rounded-lg border bg-white px-3 text-sm transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/55 <?= $filters['late'] ? 'border-brand text-brand-dark' : 'border-gray-300 text-[#1A1C1E] hover:border-brand/50' ?>"
                       data-queue-late>
                        <span class="flex h-4 w-4 items-center justify-center rounded border <?= $filters['late'] ? 'border-brand bg-brand' : 'border-gray-400 bg-white' ?>" aria-hidden="true">
                            <?php if ($filters['late']): ?><?= icon('check', 'h-3 w-3 text-white') ?><?php endif; ?>
                        </span>
                        Late only<span class="sr-only"><?= $filters['late'] ? ' (on)' : ' (off)' ?></span>
                    </a>
                <?php endif; ?>

                <?php if ($tab === 'at-risk' && $sections !== []): ?>
                    <label class="sr-only" for="grades-reason">Reason</label>
                    <select id="grades-reason" data-select-link data-risk-reason class="<?= $select ?>">
                        <?php foreach ([null => 'All reasons', 'grade' => 'Grade', 'activity' => 'Activity', 'inc' => 'INC'] as $key => $label): ?>
                            <option value="<?= esc($link(['tab' => 'at-risk', 'reason' => $key])) ?>" <?= ($reason ?? '') === (string) $key ? 'selected' : '' ?>><?= esc($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                <?php endif; ?>

                <?php if ($terms !== []): ?>
                    <label class="sr-only" for="grades-term">Term</label>
                    <select id="grades-term" data-select-link data-term-select class="<?= $select ?>">
                        <?php foreach ($terms as $term): ?>
                            <option value="<?= esc($base . '?' . http_build_query(array_filter(['tab' => $tab === 'gradebook' ? null : $tab, 'term' => $term['id']]))) ?>" <?= $term['id'] === $termId ? 'selected' : '' ?>><?= esc($term['name'] . ' (' . $term['academic_year'] . ')') ?></option>
                        <?php endforeach; ?>
                    </select>
                <?php endif; ?>

            </div>

        </div>

        <?php // The partials get the page's data and build their links the same way. ?>
        <?php $data = compact('area', 'termId', 'sections', 'selected', 'summary', 'counts', 'canManage', 'atRisk', 'reason', 'queue', 'filters', 'link'); ?>

        <?php if ($sections !== []): ?>
            <?= view('pages/grades/partials/instructor/cards', $data, ['saveData' => false]) ?>
        <?php endif; ?>

        <?= view('pages/grades/partials/instructor/' . $tab, $data, ['saveData' => false]) ?>

    <?php endif; ?>

</div>

</div>

<?= $this->endSection() ?>
