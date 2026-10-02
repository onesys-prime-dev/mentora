<?php
$filters = $filters ?? ['search' => '', 'sort' => 'activity', 'filter' => 'all'];
$hasMore = $hasMore ?? false;
$nextPage = $nextPage ?? 2;
$searching = $filters['search'] !== '' || $filters['filter'] !== 'all';
?>

<div class="flex flex-col gap-6 p-10" data-discussions-page>

    <?php
    ob_start(); ?>
        <button
            type="button"
            data-composer-open
            class="inline-flex items-center gap-2 rounded-full bg-[#0040A6] px-5 py-2 text-sm font-semibold text-white shadow transition-colors duration-300 hover:bg-[#002068]">
            <svg class="h-4 w-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 5v14M5 12h14"/></svg>
            New discussion
        </button>
    <?php $headerActions = ob_get_clean(); ?>

    <?= view('components/page-header', [
        'icon' => 'discussion',
        'title' => 'Discussions',
        'description' => 'Ask questions, share ideas and follow what your class is talking about.',
        'actions' => $headerActions,
    ], ['saveData' => false]) ?>

    <?= view('pages/courses/features/discussions/partials/composer', [], ['saveData' => false]) ?>

    <?= view('pages/courses/features/discussions/partials/toolbar', ['filters' => $filters], ['saveData' => false]) ?>

    <!-- Everything below the toolbar is what a filter, sort or search re-renders. -->
    <div
        class="flex flex-col gap-3"
        data-discussions-results
        data-has-more="<?= $hasMore ? '1' : '0' ?>"
        data-next-page="<?= esc((string) $nextPage) ?>">

        <?php foreach ($threads as $thread): ?>
            <?= view('pages/courses/features/discussions/partials/thread-row', ['thread' => $thread], ['saveData' => false]) ?>
        <?php endforeach; ?>

        <?php if ($threads === []): ?>
            <?= view('components/empty-state', [
                'icon' => 'discussion',
                'title' => $searching ? 'No discussions match' : 'No discussions yet',
                'text' => $searching ? 'Try a different search or filter.' : 'Start the first conversation for this course.',
            ], ['saveData' => false]) ?>
        <?php endif; ?>

        <div data-discussions-sentinel class="h-px"></div>
        <div data-discussions-loading class="hidden py-2 text-center text-xs text-[#767676]">Loading more…</div>

    </div>

</div>
