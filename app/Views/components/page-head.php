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
    <div class="mb-5 flex items-center gap-4 font-heading text-xs uppercase text-[#3D3D3D]">
        <a class="-ml-1 flex min-h-10 items-center gap-2 rounded px-1 transition hover:text-brand focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/50" href="<?= esc($backUrl) ?>">
            <svg class="h-4 w-4" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M15 18l-6-6 6-6" /></svg>
            <?= esc($backLabel) ?>
        </a>
    </div>
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
