<?php
// A parent looks at a child's course: the controller gives the role, the tabs it may have and a note.
$userRole = $courseRole ?? esc($user->getGroups()[0]);

$features = $courseFeatures ?? [
    'overview' => 'Overview',
    'announcements' => 'Announcements',
    'modules' => 'Modules',
    'resources' => 'Resources',
    'discussions' => 'Discussions',
    'assessments' => 'Assessments',
    'grades' => 'Grades',
];
?>

<?= $this->extend('layouts/app') ?>



<?= $this->section('content') ?>

<div class="flex flex-col lg:-mt-4 lg:h-[calc(100%+1rem)] lg:min-h-0">

<div class="flex justify-between items-center">
    <?= $this->include('components/back') ?>
</div>

<div class="flex flex-col gap-5 lg:min-h-0 lg:flex-1">

    <?php if (! empty($readOnlyView)): ?>
        <div class="px-4 sm:px-10" data-view-only>
            <?= view('components/lock-note', ['kind' => 'closed', 'text' => 'View only. You are looking at ' . $course['course_code'] . ' ' . $course['section_code'] . ' as ' . ($readOnlyAs ?? 'an administrator of this institution') . ': everything is shown as its instructor sees it, and nothing can be changed here.'], ['saveData' => false]) ?>
        </div>
    <?php endif; ?>

    <?php if (! empty($parentNote)): ?>
        <div class="px-4 sm:px-10" data-view-only>
            <?= view('components/lock-note', ['kind' => 'closed', 'text' => $parentNote], ['saveData' => false]) ?>
        </div>
    <?php endif; ?>

    <?php if (! empty($offeringClosed)): ?>
        <div class="px-4 sm:px-10" data-offering-closed>
            <?= view('components/lock-note', ['kind' => 'closed', 'text' => 'This course is closed. You can still look at it and download what is there, but nothing in it can be changed.'], ['saveData' => false]) ?>
        </div>
    <?php endif; ?>

    <div class="flex flex-col lg:min-h-0 lg:flex-1">

        <?php
        // The banner and the tabs stay pinned under the header. Once the page has scrolled, the
        // banner folds down to a strip (data-collapsed, set by the script below) so the tab's
        // content keeps the room.
        $bannerImage = $course['banner_image'] ?? base_url('assets/images/placeholder/course-tech-1.png');
        ?>
        <div
            class="group/course sticky top-(--app-header-h,4.5rem) z-20 -mt-2 flex flex-col pt-2 lg:static lg:mt-0 lg:pt-0 transition-[background-color,box-shadow] duration-200 max-lg:data-[collapsed=true]:rounded-b-3xl max-lg:data-[collapsed=true]:bg-sky-100/95 max-lg:data-[collapsed=true]:shadow-md max-lg:data-[collapsed=true]:backdrop-blur-lg lg:shrink-0"
            data-course-sticky
            data-collapsed="false"
        >

            <?php
            // Per-course artwork can be supplied later via $course['banner_image'];
            // the scrim below keeps the text readable on any image, and the solid
            // brand colour underneath is the fallback when there is none.
            ?>
            <div
                class="relative overflow-hidden rounded-3xl bg-brand-dark bg-cover bg-center bg-no-repeat text-white group-data-[collapsed=true]/course:rounded-2xl"
                style="background-image: url('<?= esc($bannerImage, 'attr') ?>');">

                <div class="absolute inset-0 bg-linear-to-r from-brand-dark/90 via-brand-dark/65 to-brand-dark/20" aria-hidden="true"></div>

                <div class="relative flex items-center gap-4 px-5 py-5 transition-[padding] duration-200 sm:gap-5 sm:px-10 sm:py-6 group-data-[collapsed=true]/course:gap-3 group-data-[collapsed=true]/course:py-2 sm:group-data-[collapsed=true]/course:gap-3 sm:group-data-[collapsed=true]/course:py-2">

                    <div class="flex h-14 w-14 shrink-0 items-center justify-center rounded-full bg-white/15 ring-1 ring-white/30 sm:h-20 sm:w-20 group-data-[collapsed=true]/course:h-9 group-data-[collapsed=true]/course:w-9 sm:group-data-[collapsed=true]/course:h-9 sm:group-data-[collapsed=true]/course:w-9">
                        <?= icon('code', 'h-7 w-7 sm:h-10 sm:w-10 group-data-[collapsed=true]/course:h-5 group-data-[collapsed=true]/course:w-5 sm:group-data-[collapsed=true]/course:h-5 sm:group-data-[collapsed=true]/course:w-5') ?>
                    </div>

                    <div class="flex min-w-0 flex-col group-data-[collapsed=true]/course:flex-row group-data-[collapsed=true]/course:items-baseline group-data-[collapsed=true]/course:gap-2">

                        <span class="text-sm font-medium text-white/85 sm:text-xl group-data-[collapsed=true]/course:shrink-0 group-data-[collapsed=true]/course:text-xs sm:group-data-[collapsed=true]/course:text-sm">
                            <?= esc($course['course_code']) . ' - ' . esc($course['section_code']) ?>
                        </span>

                        <h1 class="truncate text-2xl font-bold sm:text-3xl group-data-[collapsed=true]/course:text-base sm:group-data-[collapsed=true]/course:text-lg">
                            <?= esc($course['course_title']) ?>
                        </h1>

                        <?php if (!empty($course['term_name'])): ?>
                            <span class="mt-1 text-xs text-white/80 sm:text-sm group-data-[collapsed=true]/course:hidden">
                                <?= esc($course['term_name']) ?><?= !empty($course['academic_year']) ? ' · ' . esc($course['academic_year']) : '' ?>
                            </span>
                        <?php endif; ?>

                    </div>

                </div>

            </div>

            <div class="mt-5 flex gap-2 overflow-x-auto px-4 transition-[margin] duration-200 sm:px-10 group-data-[collapsed=true]/course:mt-2" role="tablist" aria-label="Course sections">

                <?php foreach ($features as $feature => $label): ?>

                    <?php
                    // The tab of the page being opened is marked here, in the server's HTML, so it is
                    // right from the first paint (featureLoader.highlightFeatureTab keeps it right after).
                    $isActiveTab = $feature === ($activeFeature ?? 'overview');
                    ?>

                    <button
                        type="button"
                        role="tab"
                        aria-selected="<?= $isActiveTab ? 'true' : 'false' ?>"
                        class="course-feature shrink-0 whitespace-nowrap px-4 py-1.5 cursor-pointer rounded-t-2xl focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-white sm:px-6 <?= $isActiveTab ? 'bg-white bg-linear-to-b from-sky-100 to-white !text-brand font-semibold' : 'text-white bg-blue-900 hover:bg-blue-800' ?>"
                        data-course-feature="<?= esc($feature) ?>"
                    >
                        <?= esc($label) ?>
                    </button>

                <?php endforeach; ?>

            </div>

        </div>

        <?php
        // #course-content is always part of the server's HTML and arrives already showing the shape
        // of the active tab. Only what is inside it is rendered by JavaScript: the first load adopts
        // this skeleton and replaces it with the screen (featureLoader.js).
        // The card carries a brand-tinted ring and a deeper shadow so what is inside it reads as the
        // thing to look at, against the page background.
        $skeletonShape = array_key_exists($activeFeature ?? '', $features) ? $activeFeature : 'overview';
        ?>
        <div
            class="w-full rounded-3xl bg-white shadow-xl ring-1 ring-brand/15 lg:min-h-0 lg:flex-1 lg:overflow-y-auto lg:overscroll-contain lg:scroll-smooth lg:[scrollbar-width:none] lg:[&::-webkit-scrollbar]:hidden"
            id="course-content"
            role="tabpanel"
            aria-busy="true"
            data-server-skeleton
        >
            <?= view('pages/courses/skeletons/' . $skeletonShape, ['role' => $userRole], ['saveData' => false]) ?>
        </div>

    </div>

</div>

</div>

<?= $this->endSection() ?>



<?= $this->section('scripts') ?>

<script>
// Fold the banner once the page has scrolled, and unfold it only near the top again.
// Two thresholds, not one: folding shortens the page, which can nudge scrollY back across a
// single threshold and make the banner flicker.
(function () {
    var bar = document.querySelector('[data-course-sticky]');
    if (!bar) return;
    var content = document.getElementById('course-content');
    var collapsed = false;
    var update = function () {
        var y = Math.max(window.scrollY, content ? content.scrollTop : 0);
        var next = collapsed ? y > 20 : y > 120;
        if (next === collapsed) return;
        collapsed = next;
        bar.dataset.collapsed = collapsed ? 'true' : 'false';
    };
    window.addEventListener('scroll', update, { passive: true });
    if (content) content.addEventListener('scroll', update, { passive: true });
    update();
})();
</script>

<script>
window.coursesConfig = <?= json_encode([

    'offeringId' => $course['offering_id'],

    'slug' => $course['slug'],

    'role' => $userRole,

    'browserBaseUrl' => site_url(($courseUrlPrefix ?? $userRole) . '/courses/' . $course['offering_id'] . '/' . $course['slug']),

    'apiBaseUrl' => site_url(($courseUrlPrefix ?? $userRole) . '/api/courses/' . $course['offering_id']),

    'activeFeature' => $activeFeature,

    // Which parts of the course this person may change (content, grading, announcements, discussions).
    'can' => \App\Services\Courses\CourseAbilities::for(auth()->user()),

]) ?>;
</script>

<?= $this->endSection() ?>