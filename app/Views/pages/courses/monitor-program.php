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

$link = static fn (array $override = []): string => site_url('chairperson/monitor-program')
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

$input = 'rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-[#0040A6]/55';

$percent = static fn (?float $value): string => $value === null ? '—' : rtrim(rtrim(number_format($value, 1), '0'), '.') . '%';
?>

<?= $this->extend('layouts/app') ?>

<?= $this->section('content') ?>

<div class="flex justify-between items-center">
    <?= $this->include('components/back') ?>
</div>

<div class="flex flex-col gap-6 py-3">

    <div class="flex flex-col gap-1">
        <h1 class="flex items-center gap-4 text-2xl font-bold text-[#0040A6]">
            <?= icon('score', 'h-6 w-6 shrink-0 text-[#0040A6]') ?>
            Monitor Program
        </h1>
        <p class="text-sm text-[#3D3D3D]">How the programs you chair are performing.</p>
    </div>

    <form method="get" action="<?= site_url('chairperson/monitor-program') ?>" class="grid gap-3 rounded-2xl bg-white p-4 shadow-sm sm:grid-cols-2 lg:grid-cols-4">
        <label class="flex flex-col gap-1 text-xs font-medium text-gray-600">Term
            <select class="<?= $input ?>" name="term">
                <option value="">Any term</option>
                <?php foreach ($terms as $term): ?>
                    <option value="<?= (int) $term['id'] ?>" <?= $filters->termId === (int) $term['id'] ? 'selected' : '' ?>>
                        <?= esc($term['name']) ?> (<?= esc($term['academic_year']) ?>)
                    </option>
                <?php endforeach; ?>
            </select>
        </label>
        <label class="flex flex-col gap-1 text-xs font-medium text-gray-600">From
            <input class="<?= $input ?>" type="date" name="from" value="<?= esc($filters->from ?? '') ?>">
        </label>
        <label class="flex flex-col gap-1 text-xs font-medium text-gray-600">To
            <input class="<?= $input ?>" type="date" name="to" value="<?= esc($filters->to ?? '') ?>">
        </label>
        <div class="flex items-end gap-2">
            <button type="submit" class="rounded-full bg-[#0040A6] px-5 py-2 text-sm font-semibold text-white transition hover:bg-[#002068]">Apply</button>
            <?php if ($filters->isFiltered()): ?>
                <a class="px-2 py-2 text-sm font-semibold text-[#0040A6] hover:underline" href="<?= site_url('chairperson/monitor-program') ?>">Clear</a>
            <?php endif; ?>
        </div>
    </form>

    <?php if ($loadError): ?>

        <div class="flex flex-col items-center justify-center gap-3 rounded-2xl border border-[#C33939]/30 bg-white px-8 py-14 text-center" role="alert">
            <?= icon('warning', 'h-10 w-10 text-[#C33939]') ?>
            <span class="text-sm font-semibold text-[#1A1C1E]">We could not load Monitor Program</span>
            <span class="text-xs text-[#646464]">Something went wrong on our side. Nothing has changed.</span>
            <a href="<?= site_url('chairperson/monitor-program') ?>" class="rounded-full bg-[#0040A6] px-5 py-2 text-sm font-semibold text-white transition hover:bg-[#002068] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#0040A6]/55 focus-visible:ring-offset-2">Try again</a>
        </div>

    <?php else: ?>

        <!-- Program snapshot -->
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
            <?php foreach ([['label' => 'Courses', 'value' => $snapshot['courses']], ['label' => 'Instructors', 'value' => $snapshot['instructors']], ['label' => 'Enrolled students', 'value' => $snapshot['students']]] as $tile): ?>
                <div class="rounded-2xl bg-white p-5 shadow-sm">
                    <p class="text-xs font-medium text-[#646464]"><?= esc($tile['label']) ?></p>
                    <p class="text-3xl font-bold text-[#0040A6]"><?= esc(number_format((int) $tile['value'])) ?></p>
                </div>
            <?php endforeach; ?>
        </div>

        <?php if ($comparison === []): ?>

            <div class="rounded-2xl bg-white/70">
                <?= view('components/empty-state', [
                    'icon' => 'score',
                    'title' => $filters->isFiltered() ? 'No courses match these filters' : 'Nothing to monitor yet',
                    'text' => $filters->isFiltered()
                        ? 'Try a different term or date range.'
                        : 'Courses in the programs you chair will appear here.',
                ], ['saveData' => false]) ?>
            </div>

        <?php else: ?>

            <!-- Charts -->
            <div
                id="monitor-program-charts"
                class="grid grid-cols-1 gap-5 lg:grid-cols-2"
                data-completion="<?= esc(json_encode(array_map(static fn (array $c) => ['label' => $c['course_code'], 'value' => $c['completion_percentage']], $comparison)), 'attr') ?>"
                data-grade-distribution="<?= esc(json_encode($gradeDistribution), 'attr') ?>"
                <div class="rounded-2xl bg-white p-5 shadow-sm">
                    <p class="mb-3 text-sm font-semibold text-[#1A1C1E]">Completion per course</p>
                    <canvas id="monitor-program-completion-chart" height="220"></canvas>
                </div>
                <div class="rounded-2xl bg-white p-5 shadow-sm">
                    <p class="mb-3 text-sm font-semibold text-[#1A1C1E]">Grade distribution</p>
                    <canvas id="monitor-program-grade-chart" height="220"></canvas>
                </div>
            </div>

            <!-- Course comparison -->
            <div class="rounded-2xl bg-white p-5 shadow-sm">
                <p class="mb-3 text-sm font-semibold text-[#1A1C1E]">Course comparison</p>
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-sm">
                        <thead>
                            <tr class="border-b border-gray-100 text-xs text-[#646464]">
                                <?php foreach ($sortLabel as $column => $label): ?>
                                    <th class="px-3 py-2 font-semibold">
                                        <a href="<?= esc($sortLink($column)) ?>" class="inline-flex items-center gap-1 hover:text-[#0040A6]">
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
                                        <a class="font-semibold text-[#0040A6] hover:underline" href="<?= site_url('chairperson/courses/' . $row['offering_id'] . '/' . $row['slug']) ?>">
                                            <?= esc($row['course_code']) ?> <?= esc($row['section_code']) ?>
                                        </a>
                                    </td>
                                    <td class="px-3 py-2"><?= esc((string) $row['enrolled']) ?></td>
                                    <td class="px-3 py-2"><?= esc($percent($row['completion_percentage'])) ?></td>
                                    <td class="px-3 py-2"><?= esc($percent($row['average_percentage'])) ?></td>
                                    <td class="px-3 py-2"><?= $row['avg_grading_turnaround_hours'] === null ? '—' : esc(number_format($row['avg_grading_turnaround_hours'], 1) . 'h') ?></td>
                                    <td class="px-3 py-2 text-[#646464]"><?= esc($row['instructor_name'] ?? 'Unassigned') ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- At-risk students -->
            <div class="rounded-2xl bg-white p-5 shadow-sm">
                <p class="mb-3 text-sm font-semibold text-[#1A1C1E]">At-risk students</p>
                <?php if ($atRisk === []): ?>
                    <p class="text-sm text-[#646464]">Nobody is at risk right now.</p>
                <?php else: ?>
                    <ul class="flex flex-col divide-y divide-gray-50">
                        <?php foreach ($atRisk as $student): ?>
                            <li class="flex items-center justify-between gap-3 py-2 text-sm">
                                <div>
                                    <a class="font-semibold text-[#0040A6] hover:underline" href="<?= site_url('chairperson/courses/' . $student['offering_id'] . '/' . ($student['slug'] ?? '')) ?>">
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
            <div class="rounded-2xl bg-white p-5 shadow-sm">
                <p class="mb-3 text-sm font-semibold text-[#1A1C1E]">Your recent actions</p>
                <?php if ($audit['rows'] === []): ?>
                    <p class="text-sm text-[#646464]">No activity yet.</p>
                <?php else: ?>
                    <ul class="flex flex-col divide-y divide-gray-50">
                        <?php foreach ($audit['rows'] as $entry): ?>
                            <li class="flex items-center justify-between gap-3 py-2 text-sm">
                                <div>
                                    <?php if ($entry['offering_id'] !== null && $entry['slug'] !== null): ?>
                                        <a class="font-semibold text-[#0040A6] hover:underline" href="<?= site_url('chairperson/courses/' . $entry['offering_id'] . '/' . $entry['slug']) ?>">
                                            <?= esc($entry['course_code']) ?> <?= esc($entry['section_code']) ?>
                                        </a>
                                    <?php else: ?>
                                        <span class="font-semibold text-[#1A1C1E]">—</span>
                                    <?php endif; ?>
                                    <span class="text-[#646464]"> · <?= esc($entry['description']) ?></span>
                                </div>
                                <span class="text-xs text-[#646464]"><?= esc($entry['created_at']) ?></span>
                            </li>
                        <?php endforeach; ?>
                    </ul>

                    <nav class="mt-3 flex items-center justify-between text-sm" aria-label="Audit log pages">
                        <span class="text-gray-600">Page <?= esc((string) $auditPage) ?> of <?= esc((string) $auditPages) ?></span>
                        <span class="flex gap-3">
                            <?php if ($auditPage > 1): ?>
                                <a class="font-semibold text-[#0040A6] hover:underline" href="<?= esc($auditLink($auditPage - 1)) ?>">Previous</a>
                            <?php endif; ?>
                            <?php if ($auditPage < $auditPages): ?>
                                <a class="font-semibold text-[#0040A6] hover:underline" href="<?= esc($auditLink($auditPage + 1)) ?>">Next</a>
                            <?php endif; ?>
                        </span>
                    </nav>
                <?php endif; ?>
            </div>

        <?php endif; ?>

    <?php endif; ?>

</div>

<?= $this->endSection() ?>
