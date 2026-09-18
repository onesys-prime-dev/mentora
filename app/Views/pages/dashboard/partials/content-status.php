<?php
/**
 * Modules and units still sitting in draft.
 *
 * Unglamorous but it catches the most common LMS failure: material that was
 * written and never published. Students cannot report material they can't
 * see, so nothing else on the page would surface it.
 *
 * Reads the status column on course_modules / learning_units / learning_items.
 * Placeholder rows until the query lands.
 */
$userRole = esc($user->getGroups()[0]);

$drafts = $data['drafts'] ?? [
    ['course_code' => 'IT101', 'title' => 'M3 - Number Systems', 'type' => 'Module', 'items' => 4, 'updated' => '3 days ago'],
    ['course_code' => 'IT101', 'title' => 'Quiz 2 instructions',  'type' => 'Item',   'items' => 1, 'updated' => 'Last week'],
    ['course_code' => 'IT102', 'title' => 'M2 - Functions',       'type' => 'Unit',   'items' => 6, 'updated' => 'Yesterday'],
];
?>

<!-- content status -->
<div class="h-full min-h-0 flex flex-col bg-white/20 backdrop-blur-xl border border-white/30 shadow-xl rounded-3xl overflow-clip">

    <div class="shrink-0 flex items-center justify-between px-6 py-3 bg-black/10">
        <span class="text-xl font-display">Unpublished</span>

        <?php if ($drafts !== []): ?>
            <span class="px-2.5 py-1 rounded-full text-[11px] font-medium bg-amber-500/20 text-amber-700">
                <?= count($drafts) ?> drafts
            </span>
        <?php endif; ?>
    </div>

    <div class="flex-1 min-h-0 overflow-y-auto scroll-area px-6 py-4">

        <?php if ($drafts === []): ?>

            <div class="h-full flex flex-col justify-center items-center gap-2 text-center">

                <div class="w-12 h-12 flex justify-center items-center rounded-full bg-white/40 border border-black/5">
                    <?= icon('lessons.png', 'w-6 h-6 text-gray-500') ?>
                </div>

                <span class="text-sm font-medium text-gray-700">Everything's published</span>
                <span class="text-xs text-gray-600 leading-snug">
                    Draft modules and units will be listed here.
                </span>

            </div>

        <?php else: ?>

            <div class="flex flex-col">

                <?php foreach ($drafts as $index => $draft): ?>

                    <?php if ($index > 0): ?>
                        <div class="h-px -mx-3 bg-black/5" aria-hidden="true"></div>
                    <?php endif; ?>

                    <div class="group flex items-center gap-3 py-3 px-3 -mx-3 rounded-2xl cursor-pointer transition-colors duration-150 hover:bg-white/45">

                        <div class="w-9 h-9 shrink-0 flex justify-center items-center bg-amber-600 rounded-full transition-transform duration-150 group-hover:scale-110">
                            <?= icon('lessons.png', 'w-5 h-5 text-white') ?>
                        </div>

                        <div class="flex flex-col min-w-0 flex-1">
                            <span class="text-sm font-medium leading-tight truncate transition-colors duration-150 group-hover:text-blue-900">
                                <?= esc($draft['title']) ?>
                            </span>

                            <div class="flex gap-1 text-xs text-gray-700 min-w-0">
                                <span class="font-bold shrink-0"><?= esc($draft['course_code']) ?></span>
                                <span class="shrink-0">·</span>
                                <span class="shrink-0"><?= esc($draft['type']) ?></span>
                                <span class="shrink-0">·</span>
                                <span class="truncate"><?= esc($draft['items']) ?> items</span>
                            </div>
                        </div>

                        <span class="shrink-0 text-xs text-gray-600">
                            <?= esc($draft['updated']) ?>
                        </span>

                    </div>

                <?php endforeach; ?>

            </div>

        <?php endif; ?>

    </div>

</div>
