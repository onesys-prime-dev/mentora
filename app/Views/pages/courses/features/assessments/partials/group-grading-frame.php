<?php
/**
 * The group view's frame (screen 2B, and 4E read-only): lock-note, status slot, the group list and one group's
 * detail. resources/js/pages/courses/groups/grading.js fills it. Grade Work adds its dialogs after it; the dean's
 * assessment page draws it alone, read-only (flags.canWrite false from the server).
 */
?>
<div data-gg-lock hidden><?= view('components/lock-note', ['kind' => 'closed'], ['saveData' => false]) ?></div>

<div data-gg-status></div>

<div class="grid grid-cols-1 items-start gap-6 lg:grid-cols-[17rem_minmax(0,1fr)]" data-gg-layout hidden>

    <aside class="flex flex-col gap-3 rounded-2xl border border-gray-200 bg-white p-4 shadow-sm lg:sticky lg:top-24" aria-label="Groups" data-gg-list></aside>

    <section class="flex min-w-0 flex-col gap-5 rounded-2xl border border-gray-200 bg-white p-5 shadow-sm" aria-live="polite" data-gg-detail></section>

</div>

<p class="sr-only" aria-live="polite" data-gg-live></p>
