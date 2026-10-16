<?php
/** Discussions while it loads. Mirrors features/discussions/skeleton.js. */
?>
<div class="flex animate-pulse flex-col gap-6 p-10" aria-hidden="true" data-server-skeleton-shape>

    <div class="flex flex-col gap-2">
        <div class="flex items-center gap-4">
            <div class="h-6 w-6 rounded bg-gray-200"></div>
            <div class="h-8 w-44 rounded bg-gray-200"></div>
        </div>
        <div class="h-4 w-96 max-w-full rounded bg-gray-200"></div>
    </div>

    <div class="flex flex-col gap-3 rounded-2xl border border-gray-200 bg-white p-4 lg:flex-row lg:items-center lg:justify-between">
        <div class="h-10 w-full rounded-full bg-gray-200 lg:w-80"></div>
        <div class="flex gap-2">
            <div class="h-10 w-40 rounded-full bg-gray-200"></div>
            <div class="h-10 w-40 rounded-full bg-gray-200"></div>
        </div>
    </div>

    <div class="flex flex-col gap-3">
        <?php for ($i = 0; $i < 3; $i++): ?>
            <div class="flex flex-col gap-3 rounded-2xl border border-gray-200 bg-white px-6 py-5">
                <div class="flex items-center gap-3">
                    <div class="h-6 w-72 rounded bg-gray-200"></div>
                    <div class="h-5 w-16 rounded-full bg-gray-200"></div>
                </div>
                <div class="h-4 w-full max-w-2xl rounded bg-gray-200"></div>
                <div class="flex items-center gap-4">
                    <div class="h-3 w-28 rounded bg-gray-200"></div>
                    <div class="h-3 w-16 rounded bg-gray-200"></div>
                    <div class="h-3 w-32 rounded bg-gray-200"></div>
                </div>
            </div>
        <?php endfor; ?>
    </div>

</div>
