<?php
$filters = $filters ?? ['search' => '', 'sort' => 'name', 'type' => ''];
$view = $view ?? 'list';
$level = $level ?? 'offering';
?>

<div class="flex flex-col gap-6 p-10" data-resources-page data-offering-id="<?= esc($offeringId) ?>">

    <div class="flex items-center gap-4">
        <?= icon('resources', 'h-6 w-6') ?>
        <span class="text-2xl font-bold text-[#0040A6]">Resources</span>
    </div>

    <?= view('pages/courses/features/resources/partials/toolbar', [
        'filters' => $filters,
        'view' => $view,
        'level' => $level,
    ]) ?>

    <?= view('pages/courses/features/resources/partials/breadcrumb', [
        'level' => $level,
        'module' => $module ?? null,
        'unit' => $unit ?? null,
    ]) ?>

    <?php if ($level === 'unit'): ?>

        <?php if ($resources === []): ?>

            <div class="flex flex-col items-center justify-center gap-2 rounded-2xl border border-dashed border-gray-200 px-8 py-14 text-center">
                <?= icon('folder', 'h-10 w-10 text-gray-300') ?>
                <span class="text-sm font-semibold text-[#5B5B5B]">No resources yet</span>
                <span class="text-xs text-[#939393]">There are no resources in this learning unit.</span>
            </div>

        <?php elseif ($view === 'grid'): ?>

            <?= view('pages/courses/features/resources/partials/grid-view', ['resources' => $resources]) ?>

        <?php else: ?>

            <?= view('pages/courses/features/resources/partials/list-view', ['resources' => $resources]) ?>

        <?php endif; ?>

    <?php elseif ($level === 'module'): ?>

        <?= view('pages/courses/features/resources/partials/folder-list', [
            'items' => $units,
            'kind' => 'unit',
            'emptyLabel' => 'No learning units yet.',
        ]) ?>

    <?php else: ?>

        <?= view('pages/courses/features/resources/partials/folder-list', [
            'items' => $modules,
            'kind' => 'module',
            'emptyLabel' => 'No modules yet.',
        ]) ?>

    <?php endif; ?>

</div>
