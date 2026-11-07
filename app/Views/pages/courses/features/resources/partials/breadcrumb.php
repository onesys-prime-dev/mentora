<?php
$level = $level ?? 'offering';

// Same trail at every collegeh; each ancestor is clickable, the deepest is the current page.
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

<?php // The wrapper pins (the script swaps what is inside it), so the trail itself does not. ?>
<div data-resources-breadcrumb class="sticky-bar-top lg:-mb-4 lg:pb-4">
    <?= view('components/breadcrumb', ['crumbs' => $crumbs, 'sticky' => false], ['saveData' => false]) ?>
</div>
