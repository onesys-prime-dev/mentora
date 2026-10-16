<?php
/** Resources while it loads. Mirrors features/resources/skeleton.js. */
?>
<div class="p-10 animate-pulse flex flex-col gap-6" aria-hidden="true" data-server-skeleton-shape>

    <div class="h-8 w-40 rounded bg-gray-200"></div>

    <div class="flex flex-col gap-3 rounded-2xl border border-gray-200 bg-white p-4 lg:flex-row lg:items-center lg:justify-between">
        <div class="h-10 w-full max-w-sm rounded-xl bg-gray-200"></div>
        <div class="flex gap-2">
            <div class="h-10 w-32 rounded-xl bg-gray-200"></div>
            <div class="h-10 w-32 rounded-xl bg-gray-200"></div>
        </div>
    </div>

    <div class="h-4 w-48 rounded bg-gray-200"></div>

    <div class="flex flex-col divide-y divide-gray-100 rounded-2xl border border-gray-200 bg-white">
        <?php for ($i = 0; $i < 3; $i++): ?>
            <div class="flex items-center gap-4 p-4">
                <div class="h-10 w-10 rounded-xl bg-gray-200"></div>
                <div class="flex flex-1 flex-col gap-2">
                    <div class="h-4 w-56 rounded bg-gray-200"></div>
                    <div class="h-3 w-32 rounded bg-gray-200"></div>
                </div>
            </div>
        <?php endfor; ?>
    </div>

</div>
