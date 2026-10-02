<?= view('components/breadcrumb', ['crumbs' => [
    ['label' => 'Modules', 'attrs' => 'data-modules-breadcrumb'],
    ['label' => $module['title'], 'attrs' => 'data-module-breadcrumb data-module-id="' . esc($module['id'], 'attr') . '"'],
    ['label' => $currentUnit['title']],
    ['label' => $currentItem['title']],
]], ['saveData' => false]) ?>
