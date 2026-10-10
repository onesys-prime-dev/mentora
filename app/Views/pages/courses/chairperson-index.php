<?php
/**
 * Chairperson-only Courses page (R6): search, filter, sort, paginate across
 * every course offering in the chairperson's program. Filters live in the
 * address (GET), so a filtered view can be bookmarked or shared and every
 * sort/paging link keeps it.
 *
 * @var App\Services\Courses\ChairpersonCourseFilters $filters
 */
$loadError = $loadError ?? false;

$link = static fn (array $override = []): string => site_url('chairperson/courses')
    . (($query = http_build_query($filters->toQuery($override))) === '' ? '' : '?' . $query);

$sortLink = static function (string $column) use ($filters, $link): string {
    $next = $filters->sort === $column && $filters->dir === 'asc' ? 'desc' : 'asc';

    return $link(['sort' => $column, 'dir' => $next, 'page' => null]);
};

$sortLabel = ['name' => 'Name', 'instructor' => 'Instructor', 'enrollment' => 'Enrollment', 'recent_activity' => 'Recent activity'];

$input = 'rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-[#0040A6]/55';
?>

<?= $this->extend('layouts/app') ?>

<?= $this->section('content') ?>

<div class="flex justify-between items-center">
    <?= $this->include('components/back') ?>
</div>

<div class="flex flex-col gap-6 py-3">

    <div class="flex flex-col gap-1">
        <h1 class="flex items-center gap-4 text-2xl font-bold text-[#0040A6]">
            <?= icon('hat', 'h-6 w-6 shrink-0 text-[#0040A6]') ?>
            Courses
        </h1>
        <p class="text-sm text-[#3D3D3D]">Every course in the programs you chair.</p>
    </div>

    <form method="get" action="<?= site_url('chairperson/courses') ?>" class="grid gap-3 rounded-2xl bg-white p-4 shadow-sm sm:grid-cols-2 lg:grid-cols-5">
        <label class="flex flex-col gap-1 text-xs font-medium text-gray-600 lg:col-span-2">Search
            <input class="<?= $input ?>" type="search" name="q" value="<?= esc($filters->q ?? '') ?>" placeholder="Course code, title or section">
        </label>
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
        <label class="flex flex-col gap-1 text-xs font-medium text-gray-600">Instructor
            <select class="<?= $input ?>" name="instructor">
                <option value="">Any instructor</option>
                <?php foreach ($instructors as $instructor): ?>
                    <option value="<?= (int) $instructor['id'] ?>" <?= $filters->instructorId === (int) $instructor['id'] ? 'selected' : '' ?>>
                        <?= esc($instructor['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>
        <label class="flex flex-col gap-1 text-xs font-medium text-gray-600">Status
            <select class="<?= $input ?>" name="status">
                <option value="">Any status</option>
                <?php foreach ($statuses as $status): ?>
                    <option value="<?= esc($status) ?>" <?= $filters->status === $status ? 'selected' : '' ?>><?= esc(ucfirst($status)) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <div class="flex items-end gap-2 lg:col-span-5">
            <button type="submit" class="rounded-full bg-[#0040A6] px-5 py-2 text-sm font-semibold text-white transition hover:bg-[#002068]">Apply</button>
            <?php if ($filters->isFiltered()): ?>
                <a class="px-2 py-2 text-sm font-semibold text-[#0040A6] hover:underline" href="<?= site_url('chairperson/courses') ?>">Clear</a>
            <?php endif; ?>
        </div>
    </form>

    <div class="flex flex-wrap items-center justify-between gap-3">
        <p class="text-sm text-[#646464]" aria-live="polite">
            <?= esc(number_format($total)) ?> <?= $total === 1 ? 'course' : 'courses' ?><?= $filters->isFiltered() ? ' match' : '' ?>
        </p>
        <div class="flex items-center gap-1 text-xs text-[#646464]">
            Sort:
            <?php foreach ($sortLabel as $column => $label): ?>
                <a href="<?= esc($sortLink($column)) ?>"
                   class="rounded-full px-2 py-1 font-semibold <?= $filters->sort === $column ? 'bg-[#0040A6]/10 text-[#0040A6]' : 'hover:bg-gray-100' ?>">
                    <?= esc($label) ?><?= $filters->sort === $column ? ($filters->dir === 'asc' ? ' ▲' : ' ▼') : '' ?>
                </a>
            <?php endforeach; ?>
        </div>
    </div>

    <?php if ($loadError): ?>

        <div class="flex flex-col items-center justify-center gap-3 rounded-2xl border border-[#C33939]/30 bg-white px-8 py-14 text-center" role="alert">
            <?= icon('warning', 'h-10 w-10 text-[#C33939]') ?>
            <span class="text-sm font-semibold text-[#1A1C1E]">We could not load your courses</span>
            <span class="text-xs text-[#646464]">Something went wrong on our side. Your courses have not gone anywhere.</span>
            <a href="<?= site_url('chairperson/courses') ?>" class="rounded-full bg-[#0040A6] px-5 py-2 text-sm font-semibold text-white transition hover:bg-[#002068] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#0040A6]/55 focus-visible:ring-offset-2">Try again</a>
        </div>

    <?php elseif ($courses === []): ?>

        <div class="rounded-2xl bg-white/70">
            <?= view('components/empty-state', [
                'icon' => 'hat',
                'title' => $filters->isFiltered() ? 'No courses match these filters' : 'No courses yet',
                'text' => $filters->isFiltered()
                    ? 'Try a different search, term, instructor or status.'
                    : 'Courses in the programs you chair will appear here.',
            ], ['saveData' => false]) ?>
        </div>

    <?php else: ?>

        <ul class="grid grid-cols-1 gap-5 sm:grid-cols-2 xl:grid-cols-3 2xl:grid-cols-4" aria-label="Courses in your programs">

            <?php foreach ($courses as $course): ?>
                <li>
                    <?= view('pages/courses/partials/course-card', [
                        'course' => $course,
                        'userRole' => 'chairperson',
                        'isInstructor' => true,
                    ], ['saveData' => false]) ?>
                </li>
            <?php endforeach; ?>

        </ul>

        <nav class="flex items-center justify-between text-sm" aria-label="Pages">
            <span class="text-gray-600">Page <?= esc((string) $filters->page) ?> of <?= esc((string) $pages) ?></span>
            <span class="flex gap-3">
                <?php if ($filters->page > 1): ?>
                    <a class="font-semibold text-[#0040A6] hover:underline" href="<?= esc($link(['page' => $filters->page - 1])) ?>">Previous</a>
                <?php endif; ?>
                <?php if ($filters->page < $pages): ?>
                    <a class="font-semibold text-[#0040A6] hover:underline" href="<?= esc($link(['page' => $filters->page + 1])) ?>">Next</a>
                <?php endif; ?>
            </span>
        </nav>

    <?php endif; ?>

</div>

<?= $this->endSection() ?>
