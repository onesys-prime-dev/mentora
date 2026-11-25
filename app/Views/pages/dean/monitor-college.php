<?php
/**
 * Monitor College: how the dean's college is performing, compared per
 * department and per program. Filters, grouping and sort live in the
 * address (GET), so a view can be bookmarked or shared. Every number is
 * computed server-side (MonitorCollegeService); the page's script only
 * draws the two charts from data already embedded on #monitor-program-charts.
 *
 * @var App\Services\Courses\MonitorCollegeFilters $filters
 * @var array<string, mixed> $totals
 * @var list<array<string, mixed>> $groups
 * @var list<array<string, mixed>> $atRisk
 */
$base = site_url('dean/monitor-college');

$link = static function (array $override = []) use ($filters, $base): string {
    $query = http_build_query($filters->toQuery($override));

    return $base . ($query === '' ? '' : '?' . $query);
};

$sortLink = static function (string $column) use ($filters, $link): string {
    $next = $filters->sort === $column && $filters->dir === 'asc' ? 'desc' : 'asc';

    return $link(['sort' => $column, 'dir' => $next]);
};

$percent = static fn (?float $value): string => $value === null ? '—' : rtrim(rtrim(number_format($value, 1), '0'), '.') . '%';

$duration = static function (?float $hours): string {
    if ($hours === null) {
        return '—';
    }

    return $hours < 24 ? rtrim(rtrim(number_format($hours, 1), '0'), '.') . ' h' : rtrim(rtrim(number_format($hours / 24, 1), '0'), '.') . ' days';
};

$when = static fn (?string $at): string => $at === null ? '—' : date('M j, Y', strtotime($at));

$options = static function (array $rows, string $any): array {
    $list = ['' => $any];

    foreach ($rows as $row) {
        $list[(string) $row['id']] = (string) $row['name'];
    }

    return $list;
};

$termOptions = ['' => 'Any term'];

foreach ($terms as $term) {
    $termOptions[(string) $term['id']] = $term['name'] . ' (' . $term['academic_year'] . ')';
}

$columns = [
    'name'             => $filters->compare === 'program' ? 'Program' : 'Department',
    'students'         => 'Students',
    'completion'       => 'Completion',
    'average_grade'    => 'Avg. grade',
    'grading_progress' => 'Graded',
    'turnaround'       => 'Grading turnaround',
    'at_risk'          => 'At risk',
    'last_activity'    => 'Last activity',
];

$hasCourses = (int) ($totals['courses'] ?? 0) > 0;

// What leaves is what is on screen: the same filters, grouping and sort go in the address.
$exportQuery = http_build_query($filters->toQuery());
$exportClass = 'inline-flex items-center pointer-coarse:min-h-11 rounded-full border border-gray-300 px-4 py-2 text-sm font-semibold text-[#3D3D3D] transition hover:bg-gray-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/50';
$exportSolid = 'inline-flex items-center pointer-coarse:min-h-11 rounded-full bg-brand px-5 py-2 text-sm font-semibold text-white transition hover:bg-brand-dark focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/55 focus-visible:ring-offset-2';
// The Grades page that goes with this one (the same permission opens both), for the term shown.
$gradesLink  = site_url('dean/grades') . (($filters->base->termId ?? null) === null ? '' : '?term=' . (int) $filters->base->termId);
$gradesLinks = '<a data-grade-records href="' . esc($gradesLink, 'attr') . '" class="' . $exportClass . '">View grade records →</a>';
$exportLinks = '<div class="flex flex-wrap items-center gap-2">'
    . $gradesLinks
    . '<a data-export="csv" href="' . esc(site_url('dean/reports/export/monitor') . ($exportQuery === '' ? '' : '?' . $exportQuery), 'attr') . '" class="' . $exportSolid . '">Download CSV</a>'
    . '<a data-export="print" href="' . esc(site_url('dean/reports/print') . ($exportQuery === '' ? '' : '?' . $exportQuery), 'attr') . '" class="' . $exportClass . '" target="_blank" rel="noopener">Print view</a>'
    . '</div>';
?>

<?= $this->extend('layouts/app') ?>

<?= $this->section('content') ?>

<div class="py-3">

<?php // The way back stays on the background, above the panel, not in the box. ?>
<?= view('components/back-link', ['backUrl' => site_url('dean/dashboard'), 'backLabel' => 'Back to dashboard'], ['saveData' => false]) ?>

<div class="flex flex-col gap-6 rounded-3xl bg-white p-5 shadow-sm sm:p-8" data-page-panel>

    <?= view('components/page-head', [
        'title'       => 'Monitor College',
        'icon'        => 'score',
        'description' => $collegeName . ': how its departments and programs are doing.',
        'actions'     => $loadError ? '<div class="flex flex-wrap items-center gap-2">' . $gradesLinks . '</div>' : $exportLinks,
    ], ['saveData' => false]) ?>

    <?= view('components/toolbar', [
        'surface'  => 'tinted', // on the page's white panel a white card would not stand out
        'action'   => $base,
        'filtered' => $filters->isFiltered(),
        'fields'   => [
            ['type' => 'select', 'name' => 'term', 'label' => 'Term', 'options' => $termOptions, 'value' => (string) ($filters->base->termId ?? '')],
            ['type' => 'date', 'name' => 'from', 'label' => 'From', 'value' => $filters->base->from ?? ''],
            ['type' => 'date', 'name' => 'to', 'label' => 'To', 'value' => $filters->base->to ?? ''],
            ['type' => 'select', 'name' => 'department', 'label' => 'Department', 'options' => $options($departments, 'Any department'), 'value' => (string) ($filters->departmentId ?? '')],
            ['type' => 'select', 'name' => 'program', 'label' => 'Program', 'options' => $options($programs, 'Any program'), 'value' => (string) ($filters->programId ?? '')],
        ],
    ], ['saveData' => false]) ?>

    <?php if ($loadError): ?>

        <div class="flex flex-col items-center justify-center gap-3 rounded-2xl border border-[#C33939]/30 bg-white px-8 py-14 text-center" role="alert" data-load-error>
            <?= icon('warning', 'h-10 w-10 text-[#C33939]') ?>
            <span class="text-sm font-semibold text-[#1A1C1E]">We could not load the college's numbers</span>
            <span class="text-xs text-[#646464]">Something went wrong on our side. Nothing has been lost.</span>
            <a href="<?= esc($base) ?>" class="inline-flex items-center pointer-coarse:min-h-11 rounded-full bg-brand px-5 py-2 text-sm font-semibold text-white transition hover:bg-brand-dark focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/55 focus-visible:ring-offset-2">Try again</a>
        </div>

    <?php elseif (! $hasCourses): ?>

        <?= view('components/empty-state', [
            'icon'  => 'score',
            'title' => $filters->isFiltered() ? 'No courses match these filters' : 'Nothing to monitor yet',
            'text'  => $filters->isFiltered()
                ? 'Try a different term, date range, department or program.'
                : 'Courses in your college will appear here.',
        ], ['saveData' => false]) ?>

    <?php else: ?>

        <div class="grid grid-cols-2 gap-4 lg:grid-cols-4" data-section="totals">
            <?= view('components/metric-card', ['label' => 'Students', 'value' => number_format((int) $totals['students']), 'detail' => number_format((int) $totals['courses']) . ' courses · ' . number_format((int) $totals['instructors']) . ' instructors', 'icon' => 'user'], ['saveData' => false]) ?>
            <?= view('components/metric-card', ['label' => 'Completion', 'value' => $percent($totals['completion']), 'detail' => 'Learning items finished', 'icon' => 'tasks'], ['saveData' => false]) ?>
            <?= view('components/metric-card', ['label' => 'Average grade', 'value' => $percent($totals['average_grade']), 'detail' => 'Graded ' . $percent($totals['grading_progress']) . ' of work submitted', 'icon' => 'grades'], ['saveData' => false]) ?>
            <?= view('components/metric-card', ['label' => 'At risk', 'value' => number_format((int) $totals['at_risk']), 'detail' => 'Students behind or silent', 'icon' => 'attention', 'tone' => (int) $totals['at_risk'] > 0 ? 'warning' : 'neutral'], ['saveData' => false]) ?>
        </div>

        <div
            id="monitor-program-charts"
            class="grid grid-cols-1 gap-5 lg:grid-cols-2"
            data-completion="<?= esc(json_encode(array_map(static fn (array $g) => ['label' => $g['name'], 'value' => $g['completion']], $groups)), 'attr') ?>"
            data-grade-distribution="<?= esc(json_encode($gradeDistribution), 'attr') ?>"
            data-instructor-activity="<?= esc(json_encode(array_map(static fn (array $i) => ['label' => $i['name'], 'content' => $i['content_updates'], 'announcements' => $i['announcements']], $instructorActivity)), 'attr') ?>">
            <div class="min-w-0 rounded-2xl bg-white p-5 shadow-sm">
                <p class="text-sm font-semibold text-[#1A1C1E]">Completion per <?= $filters->compare === 'program' ? 'program' : 'department' ?></p>
                <p class="mb-3 text-xs text-[#646464]">Share of learning items finished by enrolled students.</p>
                <div class="relative h-64"><canvas id="monitor-program-completion-chart" class="max-w-full"></canvas></div>
            </div>
            <div class="min-w-0 rounded-2xl bg-white p-5 shadow-sm">
                <p class="text-sm font-semibold text-[#1A1C1E]">Grade distribution</p>
                <p class="mb-3 text-xs text-[#646464]">Students per grade band across the college.</p>
                <div class="relative h-64"><canvas id="monitor-program-grade-chart" class="max-w-full"></canvas></div>
            </div>
            <div class="min-w-0 rounded-2xl bg-white p-5 shadow-sm lg:col-span-2">
                <p class="text-sm font-semibold text-[#1A1C1E]">Instructor activity</p>
                <p class="mb-3 text-xs text-[#646464]">Content updates and announcements per instructor in the chosen period.</p>
                <?php if ($instructorActivity === []): ?>
                    <p class="py-10 text-center text-sm text-[#646464]" data-instructor-activity-empty>No instructors teach in this scope yet.</p>
                <?php else: ?>
                    <div class="relative h-64"><canvas id="monitor-program-activity-chart" class="max-w-full" role="img" aria-label="Content updates and announcements per instructor"></canvas></div>
                <?php endif; ?>
            </div>
        </div>

        <section class="flex flex-col gap-3 rounded-2xl bg-white p-5 shadow-sm" aria-labelledby="compare-heading" data-section="compare">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <h2 id="compare-heading" class="text-sm font-semibold text-[#1A1C1E]">Compare</h2>
                <div class="flex items-center gap-1 text-xs" role="group" aria-label="Compare by">
                    <?php foreach (['department' => 'Departments', 'program' => 'Programs'] as $mode => $label): ?>
                        <a href="<?= esc($link(['compare' => $mode, 'sort' => null, 'dir' => null])) ?>"
                           <?= $filters->compare === $mode ? 'aria-current="true"' : '' ?>
                           class="inline-flex items-center rounded-full px-3 py-1.5 font-semibold pointer-coarse:min-h-11 <?= $filters->compare === $mode ? 'bg-brand text-white' : 'bg-gray-100 text-[#3D3D3D] hover:bg-gray-200' ?>">
                            <?= esc($label) ?>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>

            <?php // The shared pinned scroller (shared/scroll-x.js), the department or program column pinned; below lg cells keep to one line so the table scrolls instead of squeezing. ?>
            <div class="relative focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-brand/55" tabindex="0" role="region" aria-label="College figures per <?= $filters->compare === 'program' ? 'program' : 'department' ?>" data-scroll-x data-sticky-first>
                <table class="w-full text-left text-sm max-lg:whitespace-nowrap" data-college-comparison>
                    <caption class="sr-only">College figures per <?= $filters->compare === 'program' ? 'program' : 'department' ?></caption>
                    <thead>
                        <tr class="border-b border-gray-100 text-xs text-[#646464]">
                            <?php foreach ($columns as $column => $label): ?>
                                <th scope="col" class="px-3 py-2 font-semibold<?= $column === 'name' ? ' sticky left-0 z-[2] bg-white max-lg:w-36 max-lg:whitespace-normal' : '' ?>" <?= $filters->sort === $column ? 'aria-sort="' . ($filters->dir === 'asc' ? 'ascending' : 'descending') . '"' : '' ?>>
                                    <a href="<?= esc($sortLink($column)) ?>" class="inline-flex items-center gap-1 hover:text-brand">
                                        <?= esc($label) ?><?= $filters->sort === $column ? ($filters->dir === 'asc' ? ' ▲' : ' ▼') : '' ?>
                                    </a>
                                </th>
                            <?php endforeach; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($groups as $group): ?>
                            <tr class="border-b border-gray-50 hover:bg-gray-50" data-group="<?= (int) $group['id'] ?>">
                                <th scope="row" class="sticky left-0 z-[1] bg-white px-3 py-2 font-semibold max-lg:w-36 max-lg:whitespace-normal text-[#1A1C1E]">
                                    <?php if ($filters->compare === 'program'): ?>
                                        <a data-program-link class="text-brand hover:underline pointer-coarse:inline-flex pointer-coarse:min-h-11 pointer-coarse:min-w-11 pointer-coarse:items-center" href="<?= esc(site_url('dean/monitor-college/programs/' . (int) $group['id']) . (($carry = http_build_query($filters->base->toQuery())) === '' ? '' : '?' . $carry)) ?>"><?= esc($group['name']) ?></a>
                                    <?php else: ?>
                                        <?= esc($group['name']) ?>
                                    <?php endif; ?>
                                    <?php if (! empty($group['context'])): ?><span class="block text-xs font-normal text-[#646464]"><?= esc($group['context']) ?></span><?php endif; ?>
                                </th>
                                <td class="px-3 py-2"><?= esc(number_format((int) $group['students'])) ?></td>
                                <td class="px-3 py-2"><?= esc($percent($group['completion'])) ?></td>
                                <td class="px-3 py-2"><?= esc($percent($group['average_grade'])) ?></td>
                                <td class="px-3 py-2"><?= esc($percent($group['grading_progress'])) ?></td>
                                <td class="px-3 py-2"><?= esc($duration($group['turnaround'])) ?></td>
                                <td class="px-3 py-2"><?= esc((string) $group['at_risk']) ?></td>
                                <td class="px-3 py-2"><?= esc($when($group['last_activity'])) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <section class="flex flex-col gap-3 rounded-2xl bg-white p-5 shadow-sm" aria-labelledby="risk-heading" data-section="at-risk">
            <h2 id="risk-heading" class="text-sm font-semibold text-[#1A1C1E]">At-risk students</h2>
            <?php if ($atRisk === []): ?>
                <p class="text-sm text-[#646464]" data-at-risk-empty>Nobody is at risk right now.</p>
            <?php else: ?>
                <ul class="flex flex-col divide-y divide-gray-50">
                    <?php foreach ($atRisk as $student): ?>
                        <li class="flex items-center justify-between gap-3 py-2 text-sm">
                            <?php $avatar = ($atRiskAvatars ?? [])[(int) ($student['student_id'] ?? 0)] ?? null; ?>
                            <div class="flex min-w-0 items-center gap-3">
                                <?php if ($avatar !== null): ?>
                                    <?= view('components/avatar', $avatar + ['size' => 'sm', 'decorative' => true], ['saveData' => false]) ?>
                                <?php endif; ?>
                                <div>
                                <a class="font-semibold text-brand hover:underline pointer-coarse:inline-flex pointer-coarse:min-h-11 pointer-coarse:min-w-11 pointer-coarse:items-center" href="<?= esc(site_url('dean/courses/' . $student['offering_id'] . '/' . ($student['slug'] ?? 'course'))) ?>">
                                    <?= esc($student['name']) ?>
                                </a>
                                <span class="text-[#646464]"> · <?= esc((string) $student['course_code']) ?> <?= esc((string) $student['section_code']) ?></span>
                                </div>
                            </div>
                            <div class="flex items-center gap-3">
                                <a data-follow-up-link class="inline-flex items-center justify-center pointer-coarse:min-w-11 text-xs font-semibold text-brand hover:underline pointer-coarse:min-h-11" href="<?= esc(site_url('dean/follow-ups') . '?' . http_build_query(['offering' => (int) $student['offering_id'], 'student' => (int) $student['student_id'], 'reason' => 'at_risk'])) ?>">Follow up</a>
                                <span class="rounded-full px-2 py-0.5 text-xs font-semibold <?= $student['reason'] === 'inactive' ? 'bg-[#FBE8E2] text-[#A63A18]' : 'bg-[#FFF7E6] text-[#946200]' ?>">
                                    <?= $student['reason'] === 'inactive'
                                        ? ($student['days_since'] === null ? 'Never active' : $student['days_since'] . ' days silent')
                                        : $student['progress'] . '% complete' ?>
                                </span>
                            </div>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </section>

        <section class="flex flex-col gap-3 rounded-2xl bg-white p-5 shadow-sm" aria-labelledby="audit-heading" data-section="audit">
            <div class="flex flex-col gap-0.5">
                <h2 id="audit-heading" class="text-sm font-semibold text-[#1A1C1E]">Audit log</h2>
                <p class="text-xs text-[#646464]">What chairpersons changed in your college's courses: who, what, when and as which role.</p>
            </div>

            <?php if ($audit['rows'] === []): ?>
                <p class="text-sm text-[#646464]" data-audit-empty>No changes recorded<?= $filters->isFiltered() ? ' for these filters' : ' yet' ?>.</p>
            <?php else: ?>
                <?php // components/data-table: phone rows (What over Who and Course), Acting as and When in the row sheet. ?>
                <?= view('components/data-table', [
                    'id'      => 'audit-log',
                    'caption' => "Changes chairpersons made in the college's courses, newest first",
                    'avatar'  => false,
                    'columns' => [
                        ['key' => 'who', 'label' => 'Who', 'role' => 'status', 'class' => 'max-md:text-xs'],
                        ['key' => 'what', 'label' => 'What', 'role' => 'primary'],
                        ['key' => 'course', 'label' => 'Course', 'role' => 'secondary'],
                        ['key' => 'acting', 'label' => 'Acting as'],
                        ['key' => 'when', 'label' => 'When'],
                    ],
                    'rows' => array_map(static fn (array $entry): array => [
                        'id'    => $entry['id'] ?? null,
                        'label' => $entry['description'] . ', ' . ($entry['actor'] ?? '—') . ', ' . $entry['created_at'],
                        'cells' => [
                            'who'    => $entry['actor'] ?? '—',
                            // The hook sits in a cell the row sheet never copies, so each entry is counted once. The hidden
                            // words name the ⋯ button "Details for <what>, <who>, <when>" (data-table names it after this cell).
                            'what'   => ['html' => '<span data-audit-entry title="' . esc((string) $entry['description'], 'attr') . '">' . esc((string) $entry['description']) . '</span>'
                                . '<span class="sr-only">, ' . esc((string) ($entry['actor'] ?? '—')) . ', ' . esc((string) $entry['created_at']) . '</span>'],
                            'course' => $entry['offering_id'] !== null && $entry['slug'] !== null
                                ? ['html' => '<a class="font-semibold text-brand hover:underline pointer-coarse:inline-flex pointer-coarse:min-h-11 pointer-coarse:min-w-11 pointer-coarse:items-center" href="' . esc(site_url('dean/courses/' . $entry['offering_id'] . '/' . $entry['slug']), 'attr') . '">' . esc((string) $entry['course_code']) . ' ' . esc((string) $entry['section_code']) . '</a>']
                                : '—',
                            'acting' => ucfirst((string) ($entry['acting_role'] ?? '—')),
                            'when'   => (string) $entry['created_at'],
                        ],
                    ], $audit['rows']),
                ], ['saveData' => false]) ?>

                <nav class="flex items-center justify-between text-sm" aria-label="Audit log pages">
                    <span class="text-gray-600">Page <?= esc((string) $auditPage) ?> of <?= esc((string) $auditPages) ?> · <?= esc(number_format($audit['total'])) ?> in all</span>
                    <span class="flex gap-3">
                        <?php if ($auditPage > 1): ?>
                            <a class="font-semibold text-brand hover:underline pointer-coarse:inline-flex pointer-coarse:min-h-11 pointer-coarse:min-w-11 pointer-coarse:items-center" href="<?= esc($link(['audit_page' => $auditPage > 2 ? $auditPage - 1 : null])) ?>">Previous</a>
                        <?php endif; ?>
                        <?php if ($auditPage < $auditPages): ?>
                            <a class="font-semibold text-brand hover:underline pointer-coarse:inline-flex pointer-coarse:min-h-11 pointer-coarse:min-w-11 pointer-coarse:items-center" href="<?= esc($link(['audit_page' => $auditPage + 1])) ?>">Next</a>
                        <?php endif; ?>
                    </span>
                </nav>
            <?php endif; ?>
        </section>

    <?php endif; ?>

</div>

</div>

<?= $this->endSection() ?>
