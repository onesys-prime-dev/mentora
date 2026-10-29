<?php

/*
|--------------------------------------------------------------------------
| Page head — Back link, icon and title, description, actions
|--------------------------------------------------------------------------
| The head of every management page, so they all start the same way.
| Params: title (required), icon (registry name), description, backUrl,
| backLabel ("Back to dashboard"), actions (html, right-aligned, wraps under
| the title on a small screen). The title is the page's one <h1>.
|--------------------------------------------------------------------------
*/

$icon        = $icon ?? null;
$description = $description ?? '';
$backUrl     = $backUrl ?? null;
$backLabel   = $backLabel ?? 'Back';
$actions     = $actions ?? '';
?>

<?php if ($backUrl !== null): ?>
    <?= view('components/back-link', ['backUrl' => $backUrl, 'backLabel' => $backLabel], ['saveData' => false]) ?>
<?php endif; ?>

<div class="flex flex-wrap items-start justify-between gap-x-6 gap-y-3">

    <div class="flex min-w-0 flex-col gap-1">
        <h1 class="flex items-center gap-4 text-2xl font-bold text-brand">
            <?php if ($icon !== null): ?><?= icon($icon, 'h-6 w-6 shrink-0 text-brand') ?><?php endif; ?>
            <?= esc($title) ?>
        </h1>
        <?php if ($description !== ''): ?>
            <p class="text-sm text-[#3D3D3D]"><?= esc($description) ?></p>
        <?php endif; ?>
    </div>

    <?php if ($actions !== ''): ?>
        <div class="flex flex-wrap items-center gap-3"><?= $actions ?></div>
    <?php endif; ?>

</div>
