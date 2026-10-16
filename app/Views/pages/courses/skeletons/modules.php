<?php
/**
 * Modules while it loads. Mirrors features/modules/skeleton.js: a header
 * (title, a button for an instructor, description lines) and a few module
 * cards. Params: role.
 */
?>
<div class="p-10 animate-pulse flex flex-col gap-10" aria-hidden="true" data-server-skeleton-shape>

    <div class="flex flex-col gap-3">

        <div class="flex items-center justify-between gap-4">

            <div class="flex items-center gap-4">
                <div class="h-6 w-6 rounded bg-gray-200"></div>
                <div class="h-8 w-56 rounded bg-gray-200"></div>
            </div>

            <?php if (($role ?? '') === 'instructor'): ?>
                <div class="h-10 w-36 rounded-full bg-gray-200"></div>
            <?php endif; ?>

        </div>

        <div class="h-5 w-72 rounded bg-gray-200"></div>

        <div class="h-4 w-full max-w-150 rounded bg-gray-200"></div>

    </div>

    <div class="flex flex-col gap-5">
        <?php for ($i = 0; $i < 3; $i++): ?>
            <div class="rounded-2xl border border-gray-200 bg-white">
                <div class="flex justify-between items-center p-6">
                    <div class="flex gap-5 items-center flex-1">
                        <div class="h-15 w-15 rounded-full bg-gray-200"></div>
                        <div class="flex flex-col gap-3 flex-1">
                            <div class="h-6 w-64 rounded bg-gray-200"></div>
                            <div class="h-4 w-96 max-w-full rounded bg-gray-200"></div>
                        </div>
                    </div>
                </div>
            </div>
        <?php endfor; ?>
    </div>

</div>
