<?php

/*
|--------------------------------------------------------------------------
| Course Workspace page header
|--------------------------------------------------------------------------
| Params: title (required), icon (registry name), description, actions
| (pre-rendered HTML, right-aligned; wraps under the title on small screens).
| The title is a real <h2> — the course banner owns the page's <h1>.
|--------------------------------------------------------------------------
*/

$icon = $icon ?? null;
$description = $description ?? '';
$actions = $actions ?? '';
?>

<div class="flex flex-wrap items-center justify-between gap-x-6 gap-y-3">

    <div class="flex min-w-0 flex-col gap-1">

        <div class="flex items-center gap-4">

            <?php if ($icon !== null): ?>
                <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-brand/10 ring-1 ring-brand/15">
                    <?= icon($icon, 'h-6 w-6 text-brand') ?>
                </span>
            <?php endif; ?>

            <h2 class="text-2xl font-bold text-brand"><?= esc($title) ?></h2>

        </div>

        <span class="mt-1 h-1 w-14 rounded-full bg-linear-to-r from-brand to-blue-400" aria-hidden="true"></span>

        <?php if ($description !== ''): ?>
            <p class="text-sm text-[#646464]"><?= esc($description) ?></p>
        <?php endif; ?>

    </div>

    <?php if ($actions !== ''): ?>
        <div class="flex flex-wrap items-center gap-3"><?= $actions ?></div>
    <?php endif; ?>

</div>
