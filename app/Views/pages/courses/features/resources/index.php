<?php
$filters = $filters ?? ['search' => '', 'sort' => 'name', 'type' => ''];
$view = $view ?? 'list';
$level = $level ?? 'offering';
?>

<div class="flex flex-col gap-6 p-5 sm:p-8 lg:p-10" data-resources-page data-offering-id="<?= esc($offeringId) ?>">

    <?= view('components/page-header', ['icon' => 'resources', 'title' => 'Resources'], ['saveData' => false]) ?>

    <?= view('pages/courses/features/resources/partials/toolbar', [
        'filters' => $filters,
        'view' => $view,
        'level' => $level,
    ], ['saveData' => false]) ?>

    <?= view('pages/courses/features/resources/partials/breadcrumb', [
        'level' => $level,
        'module' => $module ?? null,
        'unit' => $unit ?? null,
    ], ['saveData' => false]) ?>

    <div class="flex flex-col gap-6" data-resources-content>

        <?php if ($level === 'unit'): ?>

            <?php if ($resources === []): ?>

                <?= view('components/empty-state', ['icon' => 'folder', 'title' => 'No resources yet', 'text' => 'There are no resources in this learning unit.'], ['saveData' => false]) ?>

            <?php elseif ($view === 'grid'): ?>

                <?= view('pages/courses/features/resources/partials/grid-view', ['resources' => $resources], ['saveData' => false]) ?>

            <?php else: ?>

                <?= view('pages/courses/features/resources/partials/list-view', ['resources' => $resources], ['saveData' => false]) ?>

            <?php endif; ?>

        <?php elseif ($level === 'module'): ?>

            <?= view('pages/courses/features/resources/partials/folder-list', [
                'items' => $units,
                'kind' => 'unit',
                'emptyLabel' => 'No learning units yet.',
            ], ['saveData' => false]) ?>

        <?php else: ?>

            <?= view('pages/courses/features/resources/partials/folder-list', [
                'items' => $modules,
                'kind' => 'module',
                'emptyLabel' => 'No modules yet.',
            ], ['saveData' => false]) ?>

        <?php endif; ?>

    </div>

    <?php // One sheet for every resource row's phone menu; outside the content the script swaps, so later folders find it. ?>
    <?= view('components/dialog', [
        'id'      => 'resource-actions-sheet',
        'title'   => 'Actions',
        'variant' => 'sheet',
        'body'    => '<dl class="empty:hidden" data-row-actions-fields></dl><div class="flex flex-col gap-1" data-row-actions-slot></div>',
    ], ['saveData' => false]) ?>

</div>
