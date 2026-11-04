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
 * The chairperson's bar has two tabs, My sections and Program (chairTabs: only those they may use; with one, its
 * name stands in for the bar), the term and, on Program's Sections, the search. Under the bar, the open tab's
 * lists as lighter pills (sub) with the list's filters on the right. My sections is the instructor's workbench
 * without finalizing (partials/instructor, finalizing false); Program is partials/chairperson (program, state,
 * termEnd, canNudge).
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
            'student'     => 'Your grades in every course, as they stand now.',
            'parent'      => "Your child's grades in every course.",
            'chairperson' => "Your sections and your program's.",
            default       => 'Every section you teach.',
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

    <?php elseif ($area === 'chairperson'): ?>

        <?php
        // A link to this page: the tab and its list first, then the term, then what the link is for.
        $chairLink = static function (array $params = []) use ($base, $termId): string {
            $params += ['term' => $termId];
            $ordered = array_intersect_key($params, ['tab' => true, 'sub' => true]) + ['term' => $params['term']] + $params;

            return $base . '?' . http_build_query(array_filter($ordered, static fn ($value): bool => $value !== null && $value !== '' && $value !== false));
        };

        $tabLabels = ['mine' => 'My sections', 'program' => 'Program'];
        $subLabels = [
            'mine'    => ['gradebook' => 'Gradebook', 'to-grade' => 'To grade', 'at-risk' => 'At risk'],
            'program' => ['sections' => 'Sections', 'at-risk' => 'At risk'],
        ];
        $firstSub = array_key_first($subLabels[$tab]);

        // The open list's own links (a section card, a table row, a page, a sort): its tab and list, then the rest.
        $listLink = static fn (array $params = []): string => $chairLink(['tab' => $tab, 'sub' => $sub === $firstSub ? null : $sub] + $params);

        $searching = $tab === 'program' && $sub === 'sections' && $termId !== null;

        // The list's own filters, on the right of the list pills: To grade's and At risk's, as on the instructor's page.
        $hasFilters = ($tab === 'mine' && $sub !== 'gradebook' && $sections !== [])
            || ($tab === 'program' && $sub === 'at-risk' && $program['tracker']['total'] > 0);
        ?>

        <div class="flex flex-col gap-3 rounded-2xl border border-brand/20 bg-brand-tint p-3 lg:flex-row lg:items-center lg:justify-between">

            <?php if (count($chairTabs) > 1): ?>
                <nav aria-label="Grades" class="flex flex-wrap items-center gap-2" data-grades-tabs>
                    <?php foreach ($chairTabs as $key): ?>
                        <?php $on = $tab === $key; ?>
                        <a href="<?= esc($chairLink(['tab' => $key])) ?>"
                           class="rounded-full px-4 py-1.5 text-sm font-semibold transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/55 <?= $on ? 'bg-brand text-white' : 'bg-white text-brand-dark shadow-sm hover:bg-white/70' ?>"
                           data-grades-tab="<?= esc($key) ?>"
                           <?= $on ? 'aria-current="page"' : '' ?>><?= esc($tabLabels[$key]) ?></a>
                    <?php endforeach; ?>
                </nav>
            <?php else: ?>
                <?php // One tab only: its name, not a bar with a single pill. ?>
                <h2 class="px-1 text-sm font-bold text-brand-dark" data-grades-tab-label><?= esc($tabLabels[$tab]) ?></h2>
            <?php endif; ?>

            <div class="flex flex-col gap-2 sm:flex-row sm:flex-wrap sm:items-center">

                <?php if ($terms !== []): ?>
                    <label class="sr-only" for="grades-term">Term</label>
                    <select id="grades-term" data-select-link data-term-select class="<?= $select ?>">
                        <?php foreach ($terms as $term): ?>
                            <option value="<?= esc($chairLink(['tab' => $tab, 'sub' => $sub === $firstSub ? null : $sub, 'term' => $term['id']])) ?>" <?= $term['id'] === $termId ? 'selected' : '' ?>><?= esc($term['name'] . ' (' . $term['academic_year'] . ')') ?></option>
                        <?php endforeach; ?>
                    </select>
                <?php endif; ?>

                <?php if ($searching): ?>
                    <?php // Searches as you type (shared/auto-search.js); the filter and the sort stay, the page starts over. ?>
                    <form method="get" action="<?= esc($base) ?>" role="search" class="flex" data-program-search>
                        <input type="hidden" name="tab" value="program">
                        <input type="hidden" name="term" value="<?= (int) $termId ?>">
                        <?php if ($state['nf']): ?><input type="hidden" name="nf" value="1"><?php endif; ?>
                        <?php if ($state['sort'] !== null): ?><input type="hidden" name="sort" value="<?= esc($state['sort']) ?>"><?php endif; ?>
                        <?php if ($state['dir'] !== null): ?><input type="hidden" name="dir" value="<?= esc($state['dir']) ?>"><?php endif; ?>
                        <label class="sr-only" for="program-q">Search sections</label>
                        <span class="relative flex min-w-0 flex-1">
                            <?= icon('search', 'pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-gray-400') ?>
                            <input id="program-q" type="search" name="q" data-auto-search value="<?= esc($state['q']) ?>" maxlength="100" placeholder="Search sections"
                                   class="min-h-10 min-w-0 flex-1 rounded-lg border border-gray-300 bg-white py-2 pl-9 pr-3 text-sm focus:border-brand/60 focus:outline-none focus:ring-2 focus:ring-brand/40 sm:w-56">
                        </span>
                    </form>
                <?php endif; ?>

            </div>

        </div>

        <?php // The lists inside the tab, lighter than the tabs above them; the list's filters on the right. ?>
        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">

            <nav aria-label="<?= esc($tabLabels[$tab], 'attr') ?>" class="flex flex-wrap items-center gap-2" data-grades-subs>
                <?php foreach ($subLabels[$tab] as $key => $label): ?>
                    <?php $on = $sub === $key; ?>
                    <a href="<?= esc($chairLink(['tab' => $tab, 'sub' => $key === $firstSub ? null : $key, 'section' => $tab === 'mine' && $key === 'gradebook' ? ($selected['offering_id'] ?? null) : null])) ?>"
                       class="inline-flex min-h-9 items-center gap-1.5 rounded-full border px-3.5 py-1 text-sm font-semibold transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/55 <?= $on ? 'border-brand bg-white text-brand-dark ring-1 ring-brand' : 'border-[#E3E6EE] bg-white text-[#646464] hover:border-brand/40 hover:text-brand-dark' ?>"
                       data-grades-sub="<?= esc($key) ?>"
                       <?= $on ? 'aria-current="page"' : '' ?>><span data-sub-label><?= esc($label) ?></span><?php if (isset($counts[$key])): ?><span class="rounded-full px-2 py-0.5 text-xs font-bold <?= $on ? 'bg-brand-tint text-brand-dark' : 'bg-[#F1F3F7] text-[#646464]' ?>" data-sub-count="<?= esc($key) ?>"><?= (int) $counts[$key] ?></span><?php endif; ?></a>
                <?php endforeach; ?>
            </nav>

            <?php if ($hasFilters): ?>
                <div class="flex flex-col gap-2 sm:flex-row sm:flex-wrap sm:items-center">
                    <?= view('pages/grades/partials/instructor/filters', [
                        'for'  => $sub,
                        'list' => ['tab' => $tab, 'sub' => $sub],
                        'link' => $chairLink,
                    ] + compact('sections', 'types', 'filters', 'reason', 'select'), ['saveData' => false]) ?>
                </div>
            <?php endif; ?>

        </div>

        <?php if ($tab === 'mine'): ?>

            <?php // The instructor's workbench, through the chairperson area, without finalizing (the instructor's). ?>
            <?php $data = compact('area', 'termId', 'sections', 'selected', 'summary', 'counts', 'canManage', 'atRisk', 'reason', 'queue', 'filters') + ['link' => $listLink, 'finalizing' => false]; ?>

            <?php if ($sections !== []): ?>
                <?= view('pages/grades/partials/instructor/cards', $data, ['saveData' => false]) ?>
            <?php endif; ?>

            <?= view('pages/grades/partials/instructor/' . $sub, $data, ['saveData' => false]) ?>

        <?php elseif ($sub === 'sections'): ?>

            <?= view('pages/grades/partials/chairperson/program', compact('area', 'base', 'termId', 'program', 'state', 'termEnd', 'selected', 'canManage', 'canNudge') + ['link' => $listLink], ['saveData' => false]) ?>

        <?php else: ?>

            <?= view('pages/grades/partials/chairperson/program-at-risk', compact('area', 'atRisk', 'reason'), ['saveData' => false]) ?>

        <?php endif; ?>

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

                <?php if (($tab === 'to-grade' || $tab === 'at-risk') && $sections !== []): ?>
                    <?= view('pages/grades/partials/instructor/filters', ['for' => $tab, 'list' => ['tab' => $tab]] + compact('sections', 'types', 'filters', 'reason', 'link', 'select'), ['saveData' => false]) ?>
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
