<?php

/*
|--------------------------------------------------------------------------
| The bottom sheet the module, learning unit and learning item ⋯ menus open in
| below md (shared/row-actions.js moves a row's menu entries in while it is open,
| so their own handlers run). One per page: index.php and module.php.
|--------------------------------------------------------------------------
*/
?>
<?= view('components/dialog', [
    'id'      => 'content-actions-sheet',
    'title'   => 'Actions',
    'variant' => 'sheet',
    'body'    => '<dl class="empty:hidden" data-row-actions-fields></dl><div class="flex flex-col gap-1" data-row-actions-slot></div>',
], ['saveData' => false]) ?>
