<?php
$level = $level ?? 'offering';

// Same trail at every depth; each ancestor is clickable, the deepest is the current page.
$crumbs = [['label' => 'Modules', 'attrs' => $level === 'offering' ? null : 'data-resources-crumb-root']];

if ($level !== 'offering' && $module) {
    $crumbs[] = [
        'label' => $module['title'],
        'attrs' => $level === 'unit' ? 'data-resources-crumb-module="' . esc($module['id'], 'attr') . '"' : null,
    ];
}

if ($level === 'unit' && $unit) {
    $crumbs[] = ['label' => $unit['title']];
}
?>

<div data-resources-breadcrumb>
    <?= view('components/breadcrumb', ['crumbs' => $crumbs], ['saveData' => false]) ?>
</div>
