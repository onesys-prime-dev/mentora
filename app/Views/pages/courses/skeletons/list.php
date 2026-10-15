<?php
/**
 * The shape of a list tab (Announcements, Modules, Resources, Discussions,
 * Assessments, Grades) while its content loads: a title and toolbar, then a few
 * rows. Three rows is what the JavaScript skeletons draw on a first visit
 * (shared/spa/skeleton-rows.js), so the content that replaces it does not jump.
 */
?>
<div class="p-10 animate-pulse flex flex-col gap-10" aria-hidden="true" data-server-skeleton-shape>

    <div class="flex flex-col gap-3">
        <div class="flex items-center justify-between gap-4">
            <div class="flex items-center gap-4">
                <div class="h-6 w-6 rounded bg-gray-200"></div>
                <div class="h-8 w-56 rounded bg-gray-200"></div>
            </div>
            <div class="h-10 w-36 rounded-xl bg-gray-200"></div>
        </div>
        <div class="h-4 w-80 max-w-full rounded bg-gray-200"></div>
    </div>

    <div class="flex flex-col gap-5">
        <?php for ($i = 0; $i < 3; $i++): ?>
            <div class="flex flex-col gap-3 rounded-2xl border border-gray-100 p-6">
                <div class="h-5 w-64 max-w-full rounded bg-gray-200"></div>
                <div class="h-4 w-full rounded bg-gray-100"></div>
                <div class="h-4 w-2/3 rounded bg-gray-100"></div>
            </div>
        <?php endfor; ?>
    </div>

</div>
