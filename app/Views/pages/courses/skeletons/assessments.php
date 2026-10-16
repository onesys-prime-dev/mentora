<?php
/**
 * Assessments while it loads. Mirrors features/assessments/skeleton.js
 * (renderAssessmentsSkeleton): title, four metric cards, then the list and
 * the sidebar sections. Params: role.
 */
$instructor = ($role ?? '') === 'instructor';
?>
<div class="flex animate-pulse flex-col gap-10 p-10" aria-hidden="true" data-server-skeleton-shape>

    <div class="flex items-center gap-4">
        <div class="h-6 w-6 rounded bg-gray-200"></div>
        <div class="h-8 w-40 rounded bg-gray-200"></div>
    </div>

    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <?php for ($i = 0; $i < 4; $i++): ?>
            <div class="flex flex-col gap-3 rounded-2xl border border-gray-200 px-5 py-5">
                <div class="flex items-start justify-between">
                    <div class="flex flex-col gap-2">
                        <div class="h-3 w-24 rounded bg-gray-200"></div>
                        <div class="h-7 w-20 rounded bg-gray-200"></div>
                    </div>
                    <div class="h-6 w-6 rounded bg-gray-200"></div>
                </div>
                <div class="h-3 w-32 rounded bg-gray-200"></div>
            </div>
        <?php endfor; ?>
    </div>

    <div class="flex flex-col gap-10 lg:flex-row">

        <div class="flex w-full flex-col gap-5">
            <div class="flex items-center justify-end">
                <div class="flex gap-4">
                    <div class="h-10 w-44 rounded-full bg-gray-200"></div>
                    <div class="h-10 w-64 rounded-full bg-gray-200"></div>
                    <?php if ($instructor): ?>
                        <div class="h-10 w-40 rounded-full bg-gray-200"></div>
                    <?php endif; ?>
                </div>
            </div>
            <div class="flex flex-col gap-3">
                <?php for ($i = 0; $i < 3; $i++): ?>
                    <div class="flex items-center justify-between rounded-2xl border border-gray-200 px-8 py-6">
                        <div class="flex items-center gap-5">
                            <div class="h-10 w-10 rounded-full bg-gray-200"></div>
                            <div class="flex flex-col gap-2">
                                <div class="h-5 w-64 rounded bg-gray-200"></div>
                                <div class="h-3 w-80 rounded bg-gray-200"></div>
                            </div>
                        </div>
                        <div class="h-7 w-7 rounded-full bg-gray-200"></div>
                    </div>
                <?php endfor; ?>
            </div>
        </div>

        <div class="flex w-full shrink-0 flex-col gap-10 lg:w-2/5">
            <?php for ($s = 0; $s < 2; $s++): ?>
                <div class="flex flex-col gap-4">
                    <div class="flex items-center justify-between gap-3">
                        <div class="flex items-center gap-3">
                            <div class="h-5 w-5 rounded bg-gray-200"></div>
                            <div class="h-6 w-32 rounded bg-gray-200"></div>
                        </div>
                    </div>
                    <div class="flex flex-col gap-2">
                        <?php for ($r = 0; $r < 3; $r++): ?>
                            <div class="flex items-center gap-3 rounded-2xl border border-gray-200 px-5 py-4">
                                <div class="h-8 w-8 rounded-full bg-gray-200"></div>
                                <div class="flex flex-1 flex-col gap-2">
                                    <div class="h-4 w-40 rounded bg-gray-200"></div>
                                    <div class="h-3 w-28 rounded bg-gray-200"></div>
                                </div>
                            </div>
                        <?php endfor; ?>
                    </div>
                </div>
            <?php endfor; ?>
        </div>

    </div>

</div>
