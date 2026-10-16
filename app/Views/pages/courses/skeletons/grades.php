<?php
/**
 * Grades while it loads. Mirrors features/grades/skeleton.js
 * (renderGradesSkeleton): an instructor sees the gradebook toolbar, the class
 * summary and the assessments to grade; a student sees their summary, how the
 * grade is calculated and the per-assessment breakdown. Params: role.
 */
$instructor = ($role ?? '') === 'instructor';
?>
<div class="flex animate-pulse flex-col gap-10 p-10" aria-hidden="true" data-server-skeleton-shape>

    <div class="flex items-center gap-4">
        <div class="h-6 w-6 rounded bg-gray-200"></div>
        <div class="h-8 w-32 rounded bg-gray-200"></div>
    </div>

    <?php if ($instructor): ?>
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div class="flex items-center gap-3">
                <div class="h-7 w-32 rounded-full bg-gray-200"></div>
                <div class="h-4 w-64 rounded bg-gray-200"></div>
            </div>
            <div class="flex gap-2">
                <div class="h-10 w-32 rounded-xl bg-gray-200"></div>
                <div class="h-10 w-36 rounded-xl bg-gray-200"></div>
                <div class="h-10 w-44 rounded-xl bg-gray-200"></div>
            </div>
        </div>
    <?php endif; ?>

    <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
        <?php for ($i = 0; $i < 3; $i++): ?>
            <div class="flex flex-col gap-3 rounded-2xl border border-gray-200 px-6 py-6">
                <div class="flex items-start justify-between">
                    <div class="flex flex-col gap-2">
                        <div class="h-3 w-24 rounded bg-gray-200"></div>
                        <div class="h-8 w-28 rounded bg-gray-200"></div>
                    </div>
                    <div class="h-11 w-11 rounded-full bg-gray-200"></div>
                </div>
                <div class="h-3 w-36 rounded bg-gray-200"></div>
            </div>
        <?php endfor; ?>
    </div>

    <?php if (! $instructor): ?>
        <div class="flex flex-col gap-4">
            <div class="h-3 w-48 rounded bg-gray-200"></div>
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
                <?php for ($i = 0; $i < 3; $i++): ?>
                    <div class="flex flex-col gap-2 rounded-2xl border border-gray-200 px-5 py-4">
                        <div class="flex items-center justify-between">
                            <div class="h-4 w-28 rounded bg-gray-200"></div>
                            <div class="h-4 w-10 rounded-full bg-gray-200"></div>
                        </div>
                        <div class="h-6 w-16 rounded bg-gray-200"></div>
                        <div class="h-3 w-24 rounded bg-gray-200"></div>
                    </div>
                <?php endfor; ?>
            </div>
        </div>
    <?php endif; ?>

    <div class="flex flex-col gap-3">

        <div class="h-7 w-36 rounded bg-gray-200"></div>

        <div class="flex flex-col gap-3">
            <div class="h-3 w-24 rounded bg-gray-200"></div>
            <?php for ($i = 0; $i < 3; $i++): ?>
                <div class="flex items-center justify-between rounded-2xl border border-gray-200 px-8 py-6">
                    <div class="flex items-center gap-5">
                        <div class="h-10 w-10 rounded-full bg-gray-200"></div>
                        <div class="flex flex-col gap-2">
                            <div class="h-5 w-56 rounded bg-gray-200"></div>
                            <div class="h-3 w-40 rounded bg-gray-200"></div>
                        </div>
                    </div>
                    <?php if ($instructor): ?>
                        <div class="h-7 w-28 rounded-full bg-gray-200"></div>
                    <?php else: ?>
                        <div class="h-6 w-20 rounded bg-gray-200"></div>
                    <?php endif; ?>
                </div>
            <?php endfor; ?>
        </div>

    </div>

</div>
