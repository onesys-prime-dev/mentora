<?php
/**
 * Monitor Program (R5): how the chaired program is performing, as opposed to
 * the dashboard's "what needs attention now". Filters (term, date range)
 * live in the address (GET) so a view can be bookmarked or shared, same
 * convention as the chairperson Courses page. Every metric is computed
 * server-side; the only client JS here (monitor-program.js) draws the two
 * charts from data already embedded on the page — it never re-fetches.
 *
 * @var App\Services\Courses\MonitorProgramFilters $filters
 */
$loadError = $loadError ?? false;

// The chairperson's page by default. The dean's drill-down into one program (Monitor
// Department) renders this same page and sets these to its own address, course links and words.
$monitorBase = $monitorBase ?? site_url('chairperson/monitor-program');
$monitorCourseBase  = $monitorCourseBase ?? 'chairperson';
$monitorHeading     = $monitorHeading ?? 'Monitor Program';
$monitorSubheading  = $monitorSubheading ?? 'How the programs you chair are performing.';
$monitorAuditTitle  = $monitorAuditTitle ?? 'Your recent actions';
$monitorShowActor   = $monitorShowActor ?? false;
$monitorEmptyText   = $monitorEmptyText ?? 'Courses in the programs you chair will appear here.';
$monitorBackUrl     = $monitorBackUrl ?? null;
$monitorBackLabel   = $monitorBackLabel ?? 'Back';

$link = static fn (array $override = []): string => $monitorBase
    . (($query = http_build_query($filters->toQuery($override))) === '' ? '' : '?' . $query);

$sortLink = static function (string $column) use ($filters, $sort, $dir, $link): string {
    $next = $sort === $column && $dir === 'asc' ? 'desc' : 'asc';

    return $link(['sort' => $column, 'dir' => $next]);
};

$auditLink = static fn (int $page): string => $link(['audit_page' => $page > 1 ? $page : null]);

$sortLabel = [
    'course_code' => 'Course',
    'enrolled' => 'Enrolled',
    'completion_percentage' => 'Completion',
    'average_percentage' => 'Avg. grade',
    'avg_grading_turnaround_hours' => 'Grading turnaround',
];

$input = 'rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand/55';

$percent = static fn (?float $value): string => $value === null ? '—' : rtrim(rtrim(number_format($value, 1), '0'), '.') . '%';
?>

<?= $this->extend('layouts/app') ?>

<?= $this->section('content') ?>

<div class="flex justify-between items-center">
    <?php if ($monitorBackUrl !== null): ?>
        <div class="mb-5 flex items-center gap-4 font-heading text-xs uppercase text-[#3D3D3D]">
            <a class="-ml-1 flex min-h-10 items-center gap-2 rounded px-1 transition hover:text-brand focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/50" href="<?= esc($monitorBackUrl) ?>">
                <svg class="h-4 w-4" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M15 18l-6-6 6-6" /></svg>
                <?= esc($monitorBackLabel) ?>
            </a>
        </div>
    <?php else: ?>
        <?= $this->include('components/back') ?>
    <?php endif; ?>
</div>

<div class="my-3 flex flex-col gap-6 rounded-3xl bg-white p-5 shadow-sm sm:p-8" data-page-panel>

    <div class="flex flex-col gap-1">
        <h1 class="flex items-center gap-4 text-2xl font-bold text-brand">
            <?= icon('score', 'h-6 w-6 shrink-0 text-brand') ?>
            <?= esc($monitorHeading) ?>
        </h1>
        <p class="text-sm text-[#3D3D3D]"><?= esc($monitorSubheading) ?></p>
    </div>

    <!-- One-line filter toolbar (term · date range · apply), like an analytics report bar. -->
    <form method="get" action="<?= esc($monitorBase) ?>" class="flex flex-wrap items-end gap-3 rounded-2xl border border-brand/20 bg-brand-tint px-5 py-4">
        <label class="flex min-w-48 flex-1 flex-col gap-1 text-xs font-medium text-gray-600">Term
            <select class="<?= $input ?>" name="term">
                <option value="">Any term</option>
                <?php foreach ($terms as $term): ?>
                    <option value="<?= (int) $term['id'] ?>" <?= $filters->termId === (int) $term['id'] ? 'selected' : '' ?>>
                        <?= esc($term['name']) ?> (<?= esc($term['academic_year']) ?>)
                    </option>
                <?php endforeach; ?>
            </select>
        </label>
        <div class="flex min-w-72 flex-[1.5] items-end gap-2">
            <label class="flex flex-1 flex-col gap-1 text-xs font-medium text-gray-600">From
                <input class="<?= $input ?>" type="date" name="from" value="<?= esc($filters->from ?? '') ?>">
            </label>
            <span class="pb-2.5 text-gray-400">–</span>
            <label class="flex flex-1 flex-col gap-1 text-xs font-medium text-gray-600">To
                <input class="<?= $input ?>" type="date" name="to" value="<?= esc($filters->to ?? '') ?>">
            </label>
        </div>
        <div class="flex items-end gap-2">
            <button type="submit" class="rounded-full bg-brand px-5 py-2 text-sm font-semibold text-white transition hover:bg-brand-dark">Apply</button>
            <?php if ($filters->isFiltered()): ?>
                <a class="px-2 py-2 text-sm font-semibold text-brand hover:underline" href="<?= esc($monitorBase) ?>">Clear</a>
            <?php endif; ?>
        </div>
    </form>

    <?php if ($loadError): ?>

        <div class="flex flex-col items-center justify-center gap-3 rounded-2xl border border-[#C33939]/30 bg-white px-8 py-14 text-center" role="alert">
            <?= icon('warning', 'h-10 w-10 text-[#C33939]') ?>
            <span class="text-sm font-semibold text-[#1A1C1E]">We could not load Monitor Program</span>
            <span class="text-xs text-[#646464]">Something went wrong on our side. Nothing has changed.</span>
            <a href="<?= esc($monitorBase) ?>" class="rounded-full bg-brand px-5 py-2 text-sm font-semibold text-white transition hover:bg-brand-dark focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/55 focus-visible:ring-offset-2">Try again</a>
        </div>

    <?php else: ?>

        <!-- Program snapshot -->
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
            <?php foreach ([
                ['label' => 'Courses', 'value' => $snapshot['courses'], 'icon' => 'hat', 'tint' => 'bg-[#E8EEFC] text-[#0040A6]'],
                ['label' => 'Instructors', 'value' => $snapshot['instructors'], 'icon' => 'user', 'tint' => 'bg-[#E6F4EC] text-[#1E8A4C]'],
                ['label' => 'Enrolled students', 'value' => $snapshot['students'], 'icon' => 'lessons', 'tint' => 'bg-[#FFF1D6] text-[#946200]'],
            ] as $tile): ?>
                <div class="flex items-center gap-4 rounded-2xl border border-[#E3E6EE] bg-[#F6F7FA] shadow-md p-5">
                    <span class="flex h-12 w-12 shrink-0 items-center justify-center rounded-xl <?= $tile['tint'] ?>">
                        <?= icon($tile['icon'], 'h-6 w-6') ?>
                    </span>
                    <div>
                        <p class="text-3xl font-bold leading-none text-[#0A0F1E]"><?= esc(number_format((int) $tile['value'])) ?></p>
                        <p class="mt-1 text-xs font-medium text-[#646464]"><?= esc($tile['label']) ?></p>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <?php if ($comparison === []): ?>

            <div class="rounded-2xl border border-[#E3E6EE] bg-[#F6F7FA] shadow-md">
                <?= view('components/empty-state', [
                    'icon' => 'score',
                    'title' => $filters->isFiltered() ? 'No courses match these filters' : 'Nothing to monitor yet',
                    'text' => $filters->isFiltered()
                        ? 'Try a different term or date range.'
                        : $monitorEmptyText,
                ], ['saveData' => false]) ?>
            </div>

        <?php else: ?>

            <!-- Charts -->
            <div
                id="monitor-program-charts"
                class="grid grid-cols-1 gap-5 lg:grid-cols-2"
                data-completion="<?= esc(json_encode(array_map(static fn (array $c) => ['label' => $c['course_code'], 'value' => $c['completion_percentage']], $comparison)), 'attr') ?>"
                data-grade-distribution="<?= esc(json_encode($gradeDistribution), 'attr') ?>">
                <div class="rounded-2xl border border-[#E3E6EE] bg-[#F6F7FA] shadow-md p-5">
                    <p class="text-sm font-semibold text-[#1A1C1E]">Completion per course</p>
                    <p class="mb-3 text-xs text-[#646464]">Share of learning items finished by enrolled students.</p>
                    <div class="relative h-64"><canvas id="monitor-program-completion-chart"></canvas></div>
                </div>
                <div class="rounded-2xl border border-[#E3E6EE] bg-[#F6F7FA] shadow-md p-5">
                    <p class="text-sm font-semibold text-[#1A1C1E]">Grade distribution</p>
                    <p class="mb-3 text-xs text-[#646464]">Students per grade band across the program.</p>
                    <div class="relative h-64"><canvas id="monitor-program-grade-chart"></canvas></div>
                </div>
            </div>

            <!-- Course comparison -->
            <div class="rounded-2xl border border-[#E3E6EE] bg-[#F6F7FA] shadow-md p-5">
                <p class="mb-3 text-sm font-semibold text-[#1A1C1E]">Course comparison</p>
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-sm">
                        <thead>
                            <tr class="border-b border-gray-100 text-xs text-[#646464]">
                                <?php foreach ($sortLabel as $column => $label): ?>
                                    <th class="px-3 py-2 font-semibold">
                                        <a href="<?= esc($sortLink($column)) ?>" class="inline-flex items-center gap-1 hover:text-brand">
                                            <?= esc($label) ?><?= $sort === $column ? ($dir === 'asc' ? ' ▲' : ' ▼') : '' ?>
                                        </a>
                                    </th>
                                <?php endforeach; ?>
                                <th class="px-3 py-2 font-semibold">Instructor</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($comparison as $row): ?>
                                <tr class="border-b border-gray-50 hover:bg-gray-50">
                                    <td class="px-3 py-2">
                                        <a data-course-comparison-link class="font-semibold text-brand hover:underline" href="<?= site_url($monitorCourseBase . '/courses/' . $row['offering_id'] . '/' . $row['slug']) ?>">
                                            <?= esc($row['course_code']) ?> <?= esc($row['section_code']) ?>
                                        </a>
                                    </td>
                                    <td class="px-3 py-2"><?= esc((string) $row['enrolled']) ?></td>
                                    <td class="px-3 py-2">
                                        <div class="flex items-center gap-2">
                                            <div class="h-1.5 w-20 overflow-hidden rounded-full bg-[#F1F3F8]">
                                                <div class="h-full rounded-full bg-[#0040A6]" style="width: <?= (int) round((float) ($row['completion_percentage'] ?? 0)) ?>%"></div>
                                            </div>
                                            <span><?= esc($percent($row['completion_percentage'])) ?></span>
                                        </div>
                                    </td>
                                    <td class="px-3 py-2"><?= esc($percent($row['average_percentage'])) ?></td>
                                    <td class="px-3 py-2"><?= $row['avg_grading_turnaround_hours'] === null ? '—' : esc(number_format($row['avg_grading_turnaround_hours'], 1) . 'h') ?></td>
                                    <td class="px-3 py-2 text-[#646464]"><?= esc($row['instructor_name'] ?? 'Unassigned') ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- At-risk students + audit log side by side -->
            <div class="grid grid-cols-1 items-start gap-5 lg:grid-cols-2">

            <!-- At-risk students -->
            <div class="rounded-2xl border border-[#E3E6EE] bg-[#F6F7FA] shadow-md p-5">
                <p class="mb-3 text-sm font-semibold text-[#1A1C1E]">At-risk students</p>
                <?php if ($atRisk === []): ?>
                    <p class="text-sm text-[#646464]">Nobody is at risk right now.</p>
                <?php else: ?>
                    <ul class="flex flex-col divide-y divide-gray-50">
                        <?php foreach ($atRisk as $student): ?>
                            <li class="flex items-center justify-between gap-3 py-2 text-sm">
                                <div>
                                    <a class="font-semibold text-brand hover:underline" href="<?= site_url($monitorCourseBase . '/courses/' . $student['offering_id'] . '/' . ($student['slug'] ?? '')) ?>">
                                        <?= esc($student['name']) ?>
                                    </a>
                                    <span class="text-[#646464]"> · <?= esc((string) $student['course_code']) ?> <?= esc((string) $student['section_code']) ?></span>
                                </div>
                                <span class="rounded-full px-2 py-0.5 text-xs font-semibold <?= $student['reason'] === 'inactive' ? 'bg-[#C33939]/10 text-[#C33939]' : 'bg-[#946200]/10 text-[#946200]' ?>">
                                    <?= $student['reason'] === 'inactive'
                                        ? ($student['days_since'] === null ? 'Never active' : $student['days_since'] . ' days silent')
                                        : $student['progress'] . '% complete' ?>
                                </span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>

            <!-- Audit log -->
            <div class="rounded-2xl border border-[#E3E6EE] bg-[#F6F7FA] shadow-md p-5">
                <p class="mb-3 text-sm font-semibold text-[#1A1C1E]"><?= esc($monitorAuditTitle) ?></p>
                <?php if ($audit['rows'] === []): ?>
                    <p class="text-sm text-[#646464]">No activity yet.</p>
                <?php else: ?>
                    <ul class="flex flex-col divide-y divide-gray-50">
                        <?php foreach ($audit['rows'] as $entry): ?>
                            <li class="flex items-center justify-between gap-3 py-2 text-sm">
                                <div>
                                    <?php if ($entry['offering_id'] !== null && $entry['slug'] !== null): ?>
                                        <a class="font-semibold text-brand hover:underline" href="<?= site_url($monitorCourseBase . '/courses/' . $entry['offering_id'] . '/' . $entry['slug']) ?>">
                                            <?= esc($entry['course_code']) ?> <?= esc($entry['section_code']) ?>
                                        </a>
                                    <?php else: ?>
                                        <span class="font-semibold text-[#1A1C1E]">—</span>
                                    <?php endif; ?>
                                    <span class="text-[#646464]"> · <?= $monitorShowActor && ! empty($entry['actor']) ? esc($entry['actor']) . ' · ' : '' ?><?= esc($entry['description']) ?></span>
                                </div>
                                <span class="text-xs text-[#646464]"><?= esc($entry['created_at']) ?></span>
                            </li>
                        <?php endforeach; ?>
                    </ul>

                    <nav class="mt-3 flex items-center justify-between text-sm" aria-label="Audit log pages">
                        <span class="text-gray-600">Page <?= esc((string) $auditPage) ?> of <?= esc((string) $auditPages) ?></span>
                        <span class="flex gap-3">
                            <?php if ($auditPage > 1): ?>
                                <a class="font-semibold text-brand hover:underline" href="<?= esc($auditLink($auditPage - 1)) ?>">Previous</a>
                            <?php endif; ?>
                            <?php if ($auditPage < $auditPages): ?>
                                <a class="font-semibold text-brand hover:underline" href="<?= esc($auditLink($auditPage + 1)) ?>">Next</a>
                            <?php endif; ?>
                        </span>
                    </nav>
                <?php endif; ?>
            </div>

            </div>

        <?php endif; ?>

    <?php endif; ?>

</div>

<?= $this->endSection() ?>
