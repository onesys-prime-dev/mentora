<?php
$userId = (int) $user->id;
$filters = $filters ?? [];
$pinned = $pinned ?? [];
$hasMore = $hasMore ?? false;
$nextPage = $nextPage ?? 2;
$activeFilter = !empty($filters['pinned_only']) ? 'pinned' : 'all';
?>

<div class="flex flex-col gap-6 p-10" data-announcements-page>

    <div class="flex items-center gap-4">
        <?= icon('announcement', 'h-6 w-6 text-[#0040A6]') ?>
        <span class="text-2xl font-bold text-[#0040A6]">Announcements</span>
    </div>

    <div class="flex flex-col gap-6 lg:grid lg:grid-cols-[350px_1fr] lg:items-start lg:gap-8">

        <?= view('pages/courses/features/announcements/partials/sidebar', [
            'filters' => $filters,
            'activeFilter' => $activeFilter,
            'pinned' => $pinned,
        ]) ?>

        <div class="flex min-w-0 flex-col gap-6">

            <?php if ($isInstructor): ?>

                <button
                    type="button"
                    data-composer-toggle
                    class="flex w-fit items-center gap-2 rounded-xl bg-[#0040A6] px-4 py-2 text-sm font-semibold text-white transition hover:bg-[#002068]">
                    <svg class="h-4 w-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5M16.5 3.5a2.121 2.121 0 113 3L12 14l-4 1 1-4 7.5-7.5z"/>
                    </svg>
                    Post in course
                </button>

                <div class="hidden" data-composer-wrapper>
                    <?= view('pages/courses/features/announcements/partials/composer') ?>
                </div>

            <?php endif; ?>

            <div
                class="flex flex-col gap-5"
                data-announcement-list
                data-has-more="<?= $hasMore ? '1' : '0' ?>"
                data-next-page="<?= esc((string) $nextPage) ?>">

                <?php foreach ($announcements as $announcement): ?>
                    <?= view('pages/courses/features/announcements/partials/announcement-card', [
                        'announcement' => $announcement,
                        'userId' => $userId,
                        'isInstructor' => $isInstructor,
                    ]) ?>
                <?php endforeach; ?>

                <?php if ($announcements === []): ?>
                    <div class="flex flex-col items-center justify-center gap-2 rounded-2xl border border-dashed border-gray-200 px-8 py-14 text-center">
                        <span class="text-sm font-semibold text-[#5B5B5B]">
                            <?= ($filters['search'] ?? '') !== '' || !empty($filters['pinned_only'])
                                ? 'No announcements match'
                                : 'No announcements yet' ?>
                        </span>
                        <span class="text-xs text-[#939393]">
                            <?= $isInstructor ? 'Post one above to let your students know what\'s new.' : 'Your instructor hasn\'t posted anything yet.' ?>
                        </span>
                    </div>
                <?php endif; ?>

                <div data-announcements-sentinel class="h-px"></div>
                <div data-announcements-loading class="hidden py-2 text-center text-xs text-gray-400">Loading more…</div>

            </div>

        </div>

    </div>

</div>
