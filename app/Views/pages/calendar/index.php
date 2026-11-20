<?php
/*
|--------------------------------------------------------------------------
| Calendar
|--------------------------------------------------------------------------
| Own page, not a course tab — it aggregates every course a person is in,
| the way the dashboard does, rather than scoping to one offering.
|
| The grid itself (month/day/year) is rendered entirely by calendar.js:
| PHP hands over the events for the current month as JSON so the first
| paint has no loading flash, and every view switch or prev/next after
| that re-fetches from /api/calendar/events. Reimplementing the same grid
| twice (PHP for first paint, JS for every paint after) wasn't worth the
| duplication for what's saved on one load.
|
| Clicking a date opens #calendar-modal — one shell, its body swapped by
| JS per action (Add Assessment / Add Announcement / Add Reminder / Edit
| Deadline), rather than four separate modals mostly hidden at once.
|--------------------------------------------------------------------------
*/

$userRole = esc(active_role() ?: ($user->getGroups()[0] ?? ''));

// A parent looks at a child's calendar: the role, the API addresses and a heading come from
// the controller, and nothing is added or edited (calendar.js reads the role "parent").
$calendarRole           = $calendarRole ?? $userRole;
$calendarApiBase        = $calendarApiBase ?? site_url($userRole . '/api/calendar');
$calendarCoursesApiBase = $calendarCoursesApiBase ?? site_url($userRole . '/api/courses');
$calendarHeading        = $calendarHeading ?? null;
$readOnly               = $calendarRole === 'parent';

$isInstructor = in_array($userRole, ['instructor', 'chairperson'], true);

$legend = array_values(array_filter([
    ['kind' => 'assessment',   'label' => 'Assessment',   'dot' => 'bg-[#946200]'],
    ['kind' => 'announcement', 'label' => 'Announcement', 'dot' => 'bg-brand'],
    ['kind' => 'reminder',     'label' => 'Reminder',     'dot' => 'bg-emerald-600'],
], static fn (array $item): bool => ! ($readOnly && $item['kind'] === 'reminder')));
?>

<?= $this->extend('layouts/app') ?>

<?= $this->section('content') ?>

<div class="flex flex-col lg:h-full lg:min-h-0">

    <?php if ($calendarHeading !== null): ?>
        <?= view('components/page-head', ['title' => $calendarHeading, 'icon' => 'calendar', 'backUrl' => $calendarBackUrl, 'backLabel' => 'Back to dashboard'], ['saveData' => false]) ?>
    <?php else: ?>
        <?= $this->include('components/back') ?>
    <?php endif; ?>

    <div
        id="calendar-app"
        class="flex flex-1 min-h-0 flex-col gap-5 rounded-3xl bg-white p-5 shadow-sm max-sm:-mx-gutter max-sm:rounded-none max-sm:px-0 sm:p-8 lg:min-h-0"
        data-today="<?= esc($today) ?>"
        data-role="<?= esc($calendarRole) ?>"
        data-api-base="<?= esc($calendarApiBase) ?>"
        data-courses-api-base="<?= esc($calendarCoursesApiBase) ?>"
        data-initial-events="<?= esc(json_encode($events), 'attr') ?>"
        data-courses="<?= esc(json_encode($courses), 'attr') ?>"
        data-assessment-types="<?= esc(json_encode($assessmentTypes), 'attr') ?>"
        data-abilities="<?= esc(json_encode(in_array($calendarRole, ['instructor', 'chairperson'], true) ? \App\Services\Courses\CourseAbilities::for($user) : []), 'attr') ?>">

        <?php // Below md: navigation and label on the first row, the Key and the view switch on the second.
              // Below sm the card runs edge to edge (seven 44px day columns fit a 320px phone), so the toolbar keeps the gutter. ?>
        <!-- Toolbar -->
        <div class="shrink-0 flex flex-col gap-3 max-sm:px-gutter md:flex-row md:flex-wrap md:items-center md:justify-between md:gap-4">

            <div class="flex min-w-0 flex-wrap items-center gap-x-2 gap-y-1 sm:gap-3">

                <button
                    type="button"
                    data-calendar-nav="prev"
                    class="h-10 w-10 flex items-center justify-center rounded-full border border-[#A9A9A9]/50 text-[#646464] transition hover:bg-brand/5 pointer-coarse:min-h-11 pointer-coarse:min-w-11"
                    aria-label="Previous">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7" />
                    </svg>
                </button>

                <button
                    type="button"
                    data-calendar-nav="next"
                    class="h-10 w-10 flex items-center justify-center rounded-full border border-[#A9A9A9]/50 text-[#646464] transition hover:bg-brand/5 pointer-coarse:min-h-11 pointer-coarse:min-w-11"
                    aria-label="Next">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7" />
                    </svg>
                </button>

                <button
                    type="button"
                    data-calendar-today
                    class="rounded-full border border-[#A9A9A9]/50 px-4 py-2 text-sm font-semibold text-[#646464] transition hover:bg-brand/5 pointer-coarse:min-h-11 pointer-coarse:min-w-11">
                    Today
                </button>

                <span data-calendar-label class="text-fluid-h2 font-bold text-brand lg:text-2xl"></span>

            </div>

            <div class="flex items-center justify-between gap-4">

                <!-- legend: a Key dropdown below md -->
                <details data-menu class="relative md:hidden">
                    <summary class="flex cursor-pointer list-none items-center gap-1.5 rounded-full border border-[#A9A9A9]/50 px-4 py-2 text-sm font-semibold text-[#646464] pointer-coarse:min-h-11 [&::-webkit-details-marker]:hidden">
                        Key <span aria-hidden="true">&#9662;</span>
                    </summary>
                    <div class="absolute left-0 top-full z-20 mt-2 flex w-48 flex-col gap-2 rounded-2xl border border-gray-200 bg-white p-3 shadow-lg">
                        <?php foreach ($legend as $item): ?>
                            <span class="flex items-center gap-2 text-sm text-[#646464]">
                                <span class="h-2 w-2 rounded-full <?= $item['dot'] ?>"></span>
                                <?= esc($item['label']) ?>
                            </span>
                        <?php endforeach; ?>
                    </div>
                </details>

                <div class="hidden md:flex items-center gap-4">
                    <?php foreach ($legend as $item): ?>
                        <span class="flex items-center gap-1.5 text-xs text-[#646464]">
                            <span class="h-2 w-2 rounded-full <?= $item['dot'] ?>"></span>
                            <?= esc($item['label']) ?>
                        </span>
                    <?php endforeach; ?>
                </div>

                <!-- view switch -->
                <div class="flex rounded-full border border-[#A9A9A9]/50 p-1 text-sm font-semibold">

                    <?php foreach (['day' => 'Day', 'month' => 'Month', 'year' => 'Year'] as $view => $label): ?>
                        <button
                            type="button"
                            data-calendar-view="<?= esc($view) ?>"
                            class="rounded-full px-4 py-1.5 font-semibold text-[#646464] transition pointer-coarse:min-h-11 pointer-coarse:min-w-11 <?= $view === 'month' ? 'bg-brand text-white' : '' ?>">
                            <?= esc($label) ?>
                        </button>
                    <?php endforeach; ?>

                </div>

            </div>

        </div>

        <!-- Grid -->
        <div class="flex-1 min-h-0 rounded-2xl border border-[#A9A9A9]/50 overflow-hidden max-sm:rounded-none max-sm:border-x-0">
            <?php // The grid arrives already showing a month's shape; calendar.js replaces it with the real grid. ?>
            <div id="calendar-body" class="h-full min-h-[420px] overflow-y-auto scroll-area bg-white" aria-busy="true">
                <div class="grid h-full grid-cols-7 animate-pulse" aria-hidden="true" data-server-skeleton-shape>
                    <?php for ($i = 0; $i < 35; $i++): ?>
                        <div class="min-h-14 border-b border-r border-gray-100 p-2 md:min-h-[84px]">
                            <div class="h-3 w-5 rounded bg-gray-200"></div>
                            <?php if ($i % 4 === 1): ?>
                                <div class="mt-2 h-4 w-4/5 rounded bg-gray-100"></div>
                            <?php endif; ?>
                        </div>
                    <?php endfor; ?>
                </div>
            </div>
        </div>

    </div>

</div>

<?php if ($isInstructor): ?>
    <!-- The announcements page's own composer, cloned into the modal by calendar.js -->
    <template id="calendar-announcement-composer">
        <?= $this->include('pages/courses/features/announcements/partials/composer') ?>
    </template>
<?php endif; ?>

<!-- One modal shell, body swapped per action by calendar.js. Below sm it is a bottom sheet (app.css: the rise). -->
<div
    id="calendar-modal"
    class="hidden fixed inset-0 z-50 flex items-end justify-center bg-black/40 p-0 sm:items-center sm:p-4"
    role="dialog"
    aria-modal="true"
    aria-labelledby="calendar-modal-title">

    <div data-calendar-modal-panel class="flex max-h-[85dvh] w-full max-w-2xl flex-col gap-5 overflow-y-auto overscroll-contain rounded-t-2xl bg-white p-6 shadow-xl max-sm:pt-3 max-sm:pb-safe-4 sm:max-h-full sm:rounded-2xl">

        <div data-dialog-handle class="-my-2 flex justify-center py-2 sm:hidden" aria-hidden="true"><span class="h-1.5 w-10 rounded-full"></span></div>

        <div class="flex items-center justify-between">
            <span id="calendar-modal-title" data-calendar-modal-title class="text-lg font-bold text-brand"></span>

            <button type="button" data-calendar-modal-close class="flex items-center justify-center text-[#A9A9A9] transition hover:text-[#646464] pointer-coarse:min-h-11 pointer-coarse:min-w-11" aria-label="Close">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                </svg>
            </button>
        </div>

        <div data-calendar-modal-body class="flex flex-col gap-4"></div>

    </div>

</div>

<?= $this->endSection() ?>
