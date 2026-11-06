<?php

/*
|--------------------------------------------------------------------------
| "Copy to" and "Move to" for a module, a learning unit or a learning item:
| two entries of an actions menu (variant "menu") or two icon buttons
| (variant "icon"). Both open the dialog in partials/modals/content-transfer
| (modals/content-transfer.js).
|
| Params: kind (module|unit|item), id, name, variant (menu|icon)
|--------------------------------------------------------------------------
*/

$variant = $variant ?? 'menu';

$icons = [
    'copy' => '<path stroke-linecap="round" stroke-linejoin="round" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z"/>',
    'move' => '<path stroke-linecap="round" stroke-linejoin="round" d="M13 7l5 5m0 0l-5 5m5-5H6"/>',
];

$entries = ['copy' => 'Copy to…', 'move' => 'Move to…'];
?>
<?php foreach ($entries as $action => $label): ?>

    <?php if ($variant === 'icon'): ?>

        <button
            type="button"
            title="<?= esc($label) ?>"
            aria-label="<?= esc($label) ?>"
            class="flex h-10 w-10 items-center justify-center rounded-full text-gray-500 transition hover:bg-gray-100 hover:text-brand"
            data-transfer
            data-transfer-action="<?= $action ?>"
            data-transfer-kind="<?= esc($kind) ?>"
            data-transfer-id="<?= esc($id) ?>"
            data-transfer-name="<?= esc($name) ?>">
            <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><?= $icons[$action] ?></svg>
        </button>

    <?php else: ?>

        <button
            type="button"
            class="flex w-full items-center gap-3 px-4 py-2.5 text-left text-sm text-gray-700 transition hover:bg-gray-50 hover:text-brand cursor-pointer"
            data-transfer
            data-transfer-action="<?= $action ?>"
            data-transfer-kind="<?= esc($kind) ?>"
            data-transfer-id="<?= esc($id) ?>"
            data-transfer-name="<?= esc($name) ?>">
            <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><?= $icons[$action] ?></svg>
            <?= esc($label) ?>
        </button>

    <?php endif; ?>

<?php endforeach; ?>
