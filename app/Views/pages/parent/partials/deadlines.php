<?php
/**
 * What is due in the next 14 days: title, course, when, and whether the child
 * has handed it in. Never a score.
 *
 * @var list<array{title: string, course_code: string, due_ts: int, status: string}> $deadlines
 */
?>

<section class="flex flex-col gap-3 rounded-2xl bg-white p-6 shadow-sm" aria-labelledby="parent-deadlines" data-parent-deadlines>
    <h2 id="parent-deadlines" class="text-sm font-bold text-brand">Coming up in the next 14 days</h2>

    <?php if ($deadlines === []): ?>
        <p class="text-sm text-[#646464]">No deadlines in the next 14 days.</p>
    <?php else: ?>
        <ul class="flex flex-col divide-y divide-gray-100">
            <?php foreach ($deadlines as $deadline): ?>
                <li class="flex flex-wrap items-center justify-between gap-x-4 gap-y-1 py-2 text-sm">
                    <span class="text-[#646464]"><?= esc(date('D j M, g:i A', $deadline['due_ts'])) ?></span>
                    <span class="min-w-0 flex-1 font-semibold text-[#1A1C1E]"><?= esc($deadline['title']) ?> <span class="font-normal text-[#646464]">· <?= esc($deadline['course_code']) ?></span></span>
                    <span class="text-xs font-medium text-[#3D3D3D]"><?= esc($deadline['status']) ?></span>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</section>
