<?php
/**
 * Personal, student-created nudges — distinct from Upcoming Tasks, which
 * are instructor-set deadlines. Empty is the normal state here, so the
 * empty state is the design rather than an afterthought.
 */
$reminders = $data['reminders'] ?? [];
?>

<!-- reminders -->
<div class="h-full min-h-0 flex flex-col bg-white/20 backdrop-blur-xl border border-white/30 shadow-xl rounded-3xl overflow-clip">

    <div class="shrink-0 flex items-center justify-between px-6 py-3 bg-black/10">
        <span class="text-xl font-display">Reminders</span>

        <button
            type="button"
            class="w-7 h-7 flex justify-center items-center rounded-full bg-white/40 border border-black/5 shadow-sm cursor-pointer transition hover:bg-white/70"
            aria-label="Add a reminder">
            <svg class="w-4 h-4 text-gray-700" viewBox="0 0 24 24" fill="none"
                 stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
                <path d="M12 5v14M5 12h14" />
            </svg>
        </button>
    </div>

    <div class="flex-1 min-h-0 overflow-y-auto scroll-area px-6 py-4">

        <?php if ($reminders === []): ?>

            <div class="h-full flex flex-col justify-center items-center gap-2 text-center">

                <div class="w-12 h-12 flex justify-center items-center rounded-full bg-white/40 border border-black/5">
                    <?= icon('bell.png', 'w-6 h-6 text-gray-500') ?>
                </div>

                <span class="text-sm font-medium text-gray-700">You're all caught up</span>
                <span class="text-xs text-gray-600 leading-snug">
                    Add a reminder to keep track of your own to-dos.
                </span>

            </div>

        <?php else: ?>

            <div class="flex flex-col divide-y divide-black/5">

                <?php foreach ($reminders as $reminder): ?>

                    <div class="flex items-start gap-3 py-3 first:pt-0 last:pb-0">

                        <span class="mt-1.5 w-2 h-2 shrink-0 rounded-full bg-blue-800"></span>

                        <div class="flex flex-col min-w-0 flex-1">
                            <span class="text-sm leading-tight">
                                <?= esc($reminder['title']) ?>
                            </span>

                            <span class="text-xs text-gray-600">
                                <?= esc($reminder['due'] ?? '') ?>
                            </span>
                        </div>

                    </div>

                <?php endforeach; ?>

            </div>

        <?php endif; ?>

    </div>

</div>
