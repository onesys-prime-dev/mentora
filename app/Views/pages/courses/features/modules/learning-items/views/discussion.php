<?php if (!empty($discussion)): ?>

    <?= view('pages/courses/features/discussions/partials/thread-body', [
        'thread' => $discussion['thread'],
        'replies' => $discussion['replies'],
    ], ['saveData' => false]) ?>

<?php else: ?>

    <section class="flex flex-col items-center justify-center gap-2 rounded-2xl border border-dashed border-gray-300 bg-gray-50 px-8 py-16 text-center">
        <?= icon('discussion', 'h-10 w-10') ?>
        <span class="text-sm font-semibold text-[#646464]">Discussion unavailable</span>
        <span class="text-xs text-[#767676]">This discussion couldn't be loaded.</span>
    </section>

<?php endif; ?>
