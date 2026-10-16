<?php
/**
 * The shape of the Overview tab while its content loads, written into the first
 * HTML of a course page so there is never an empty card. It mirrors
 * resources/js/pages/courses/features/overview/skeleton.js (metric cards across
 * the top, a main column and a side column), so the content that replaces it does not jump.
 *
 * Params: role (instructor sees four metric cards, a student three).
 */
$metricCount = ($role ?? '') === 'instructor' ? 4 : 3;
?>
<div class="p-10 animate-pulse flex flex-col gap-8" aria-hidden="true" data-server-skeleton-shape>

    <div class="flex items-center gap-4">
        <div class="h-6 w-6 rounded bg-gray-200"></div>
        <div class="h-8 w-40 rounded bg-gray-200"></div>
    </div>

    <div class="grid grid-cols-1 gap-6 sm:grid-cols-2 <?= $metricCount === 4 ? 'lg:grid-cols-4' : 'lg:grid-cols-3' ?>">
        <?php for ($i = 0; $i < $metricCount; $i++): ?>
            <div class="flex flex-col gap-3 rounded-2xl border border-gray-100 p-5">
                <div class="h-4 w-24 rounded bg-gray-200"></div>
                <div class="h-8 w-16 rounded bg-gray-200"></div>
            </div>
        <?php endfor; ?>
    </div>

    <div class="grid grid-cols-1 gap-8 xl:grid-cols-5">

        <div class="flex min-w-0 flex-col gap-8 xl:col-span-3">
            <div class="h-40 rounded-2xl bg-gray-100"></div>
            <div class="h-28 rounded-2xl bg-gray-100"></div>
            <div class="h-48 rounded-2xl bg-gray-100"></div>
        </div>

        <div class="flex min-w-0 flex-col gap-6 xl:col-span-2">
            <div class="h-32 rounded-2xl bg-gray-100"></div>
            <div class="grid grid-cols-1 gap-6 sm:grid-cols-2">
                <?php for ($i = 0; $i < 4; $i++): ?>
                    <div class="h-20 rounded-2xl bg-gray-100"></div>
                <?php endfor; ?>
            </div>
        </div>

    </div>

</div>
