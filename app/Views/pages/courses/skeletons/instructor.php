<?php
/**
 * "From the instructor" while it loads (a parent's tab). Mirrors features/instructor/skeleton.js:
 * a title, a button row, then post cards.
 *
 * Params: role.
 */
?>
<div class="p-10 animate-pulse flex flex-col gap-8" aria-hidden="true" data-server-skeleton-shape>

    <div class="h-8 w-56 rounded bg-gray-200"></div>

    <div class="h-10 w-48 rounded-full bg-gray-200"></div>

    <div class="flex flex-col gap-4">
        <?php for ($i = 0; $i < 3; $i++): ?>
            <div class="h-24 rounded-2xl border border-gray-200 bg-gray-100"></div>
        <?php endfor; ?>
    </div>

</div>
