<?php
/**
 * The dean's Courses page: search, filter, sort and page through every
 * course offering in their department. Filters live in the address (GET), so
 * a filtered view can be bookmarked and every sort and paging link keeps it.
 *
 * @var App\Services\Courses\DeanCourseFilters $filters
 * @var list<array<string, mixed>> $courses
 */
$base  = site_url('dean/courses');
$query = $filters->toQuery(['page' => null]);

$sortLink = static function (string $column) use ($filters, $base): string {
    $next  = $filters->sort === $column && $filters->dir === 'asc' ? 'desc' : 'asc';
    $query = http_build_query($filters->toQuery(['sort' => $column, 'dir' => $next, 'page' => null]));

    return $base . ($query === '' ? '' : '?' . $query);
};

$sortLabel = ['name' => 'Name', 'instructor' => 'Instructor', 'enrollment' => 'Enrollment', 'recent_activity' => 'Recent activity'];

$options = static function (array $rows, string $any, string $label = 'name'): array {
    $list = ['' => $any];

    foreach ($rows as $row) {
        $list[(string) $row['id']] = (string) $row[$label];
    }

    return $list;
};

$termOptions = ['' => 'Any term'];

foreach ($terms as $term) {
    $termOptions[(string) $term['id']] = $term['name'] . ' (' . $term['academic_year'] . ')';
}

$statusOptions = ['' => 'Any status'];

foreach ($statuses as $status) {
    $statusOptions[$status] = ucfirst($status);
}
?>

<?= $this->extend('layouts/app') ?>

<?= $this->section('content') ?>

<div class="flex flex-col gap-6 py-3">

    <?= view('components/page-head', [
        'title'       => 'Courses',
        'icon'        => 'hat',
        'description' => 'Every course in your department. You can look, not change.',
        'backUrl'     => site_url('dean/dashboard'),
        'backLabel'   => 'Back to dashboard',
    ], ['saveData' => false]) ?>

    <?= view('components/toolbar', [
        'action'   => $base,
        'filtered' => $filters->isFiltered(),
        'fields'   => [
            ['type' => 'search', 'name' => 'q', 'label' => 'Search', 'placeholder' => 'Course code, title or section', 'value' => $filters->q ?? '', 'span' => 'sm:col-span-2'],
            ['type' => 'select', 'name' => 'term', 'label' => 'Term', 'options' => $termOptions, 'value' => (string) ($filters->termId ?? '')],
            ['type' => 'select', 'name' => 'sub_department', 'label' => 'Sub-department', 'options' => $options($subDepartments, 'Any sub-department'), 'value' => (string) ($filters->subDepartmentId ?? '')],
            ['type' => 'select', 'name' => 'program', 'label' => 'Program', 'options' => $options($programs, 'Any program'), 'value' => (string) ($filters->programId ?? '')],
            ['type' => 'select', 'name' => 'instructor', 'label' => 'Instructor', 'options' => $options($instructors, 'Any instructor'), 'value' => (string) ($filters->instructorId ?? '')],
            ['type' => 'select', 'name' => 'status', 'label' => 'Status', 'options' => $statusOptions, 'value' => (string) ($filters->status ?? '')],
        ],
    ], ['saveData' => false]) ?>

    <div class="flex flex-wrap items-center justify-between gap-3">
        <p class="text-sm text-[#646464]" aria-live="polite" data-course-count>
            <?= esc(number_format($total)) ?> <?= $total === 1 ? 'course' : 'courses' ?><?= $filters->isFiltered() ? ' match' : '' ?>
        </p>
        <div class="flex items-center gap-1 text-xs text-[#646464]">
            Sort:
            <?php foreach ($sortLabel as $column => $label): ?>
                <a href="<?= esc($sortLink($column)) ?>"
                   <?= $filters->sort === $column ? 'aria-current="true"' : '' ?>
                   class="rounded-full px-2 py-1 font-semibold <?= $filters->sort === $column ? 'bg-brand/10 text-brand' : 'hover:bg-gray-100' ?>">
                    <?= esc($label) ?><?= $filters->sort === $column ? ($filters->dir === 'asc' ? ' ▲' : ' ▼') : '' ?>
                </a>
            <?php endforeach; ?>
        </div>
    </div>

    <?php if ($loadError): ?>

        <div class="flex flex-col items-center justify-center gap-3 rounded-2xl border border-[#C33939]/30 bg-white px-8 py-14 text-center" role="alert" data-load-error>
            <?= icon('warning', 'h-10 w-10 text-[#C33939]') ?>
            <span class="text-sm font-semibold text-[#1A1C1E]">We could not load your department's courses</span>
            <span class="text-xs text-[#646464]">Something went wrong on our side. Nothing has been lost.</span>
            <a href="<?= esc($base) ?>" class="rounded-full bg-brand px-5 py-2 text-sm font-semibold text-white transition hover:bg-brand-dark focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/55 focus-visible:ring-offset-2">Try again</a>
        </div>

    <?php elseif ($courses === []): ?>

        <?= view('components/empty-state', [
            'icon'  => 'hat',
            'title' => $filters->isFiltered() ? 'No courses match these filters' : 'No courses yet',
            'text'  => $filters->isFiltered()
                ? 'Try a different search, term, sub-department, program, instructor or status.'
                : 'Courses in your department will appear here.',
        ], ['saveData' => false]) ?>

    <?php else: ?>

        <ul class="grid grid-cols-1 gap-5 sm:grid-cols-2 xl:grid-cols-3 2xl:grid-cols-4" aria-label="Courses in your department">
            <?php foreach ($courses as $course): ?>
                <li>
                    <?= view('pages/courses/partials/course-card', [
                        'course'       => $course + ['slug' => url_title((string) $course['course_title'], '-', true)],
                        'userRole'     => 'dean',
                        'isInstructor' => true,
                        'linked'       => false,
                    ], ['saveData' => false]) ?>
                </li>
            <?php endforeach; ?>
        </ul>

        <?= view('components/pagination', ['page' => $filters->page, 'pages' => $pages, 'total' => $total, 'baseUrl' => $base, 'query' => $query], ['saveData' => false]) ?>

    <?php endif; ?>

</div>

<?= $this->endSection() ?>
