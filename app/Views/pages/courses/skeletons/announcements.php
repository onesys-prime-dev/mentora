<?php
/**
 * Announcements while it loads. Mirrors features/announcements/skeleton.js:
 * a title, then a filter sidebar and the main column (a composer button for an
 * instructor, then cards).
 *
 * Params: role.
 */
?>
<div class="p-10 animate-pulse flex flex-col gap-6" aria-hidden="true" data-server-skeleton-shape>

    <div class="h-8 w-56 rounded bg-gray-200"></div>

    <div class="flex flex-col gap-6 lg:grid lg:grid-cols-[350px_1fr] lg:items-start lg:gap-8">

        <div class="flex flex-col gap-6">
            <div class="flex flex-col gap-4 rounded-2xl border border-gray-200 bg-white p-5">
                <div class="h-3 w-32 rounded bg-gray-200"></div>
                <div class="h-10 w-full rounded-xl bg-gray-200"></div>
                <div class="flex gap-2">
                    <div class="h-7 w-16 rounded-full bg-gray-200"></div>
                    <div class="h-7 w-20 rounded-full bg-gray-200"></div>
                </div>
                <div class="h-10 w-full rounded-xl bg-gray-200"></div>
            </div>
        </div>

        <div class="flex min-w-0 flex-col gap-6">
            <?php if (($role ?? '') === 'instructor'): ?>
                <div class="h-10 w-48 rounded-xl bg-gray-200"></div>
            <?php endif; ?>
            <div class="flex flex-col gap-5">
                <?php for ($i = 0; $i < 3; $i++): ?>
                    <div class="flex flex-col gap-3 rounded-2xl border border-gray-200 bg-white p-6">
                        <div class="h-5 w-64 rounded bg-gray-200"></div>
                        <div class="h-4 w-full rounded bg-gray-200"></div>
                        <div class="h-4 w-2/3 rounded bg-gray-200"></div>
                    </div>
                <?php endfor; ?>
            </div>
        </div>

    </div>

</div>
