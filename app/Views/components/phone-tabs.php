<?php

/*
|--------------------------------------------------------------------------
| Phone tabs — switch a dashboard's panels below lg (shared/phone-tabs.js)
|--------------------------------------------------------------------------
| Params: label (required): the tablist's aria-label; tabs (required): list of
| ['key' => string, 'label' => string, 'dot' => bool].
|
| The page marks its panels with data-phone-panel="{key}"; the first tab's panels
| also carry data-phone-active (app.css hides the rest below lg, so the first tab
| shows without script). A tab may own several panels, so there is no aria-controls.
| Hidden from lg up, where the page shows everything.
|--------------------------------------------------------------------------
*/
?>

<?php // Opaque bg-white base: the dark-mode mapping covers plain classes, not lg:-prefixed ones. ?>
<div data-phone-tabs class="lg:hidden sticky top-[var(--app-header-h,0px)] z-20 -mx-gutter bg-white px-gutter py-2">
    <div role="tablist" aria-label="<?= esc($label) ?>" class="flex gap-1 rounded-xl bg-gray-100 p-1">
        <?php foreach ($tabs as $i => $tab): ?>
            <button
                type="button"
                role="tab"
                data-phone-tab="<?= esc($tab['key']) ?>"
                aria-selected="<?= $i === 0 ? 'true' : 'false' ?>"
                <?= $i === 0 ? '' : 'tabindex="-1"' ?>
                class="relative inline-flex min-h-11 flex-1 items-center justify-center gap-1.5 rounded-lg px-2 text-sm font-semibold text-[#646464] transition-colors duration-[var(--motion-fast)] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/50 aria-selected:bg-brand aria-selected:text-white pointer-coarse:active:scale-[0.98]">
                <?= esc($tab['label']) ?>
                <?php if (! empty($tab['dot'])): ?>
                    <span data-phone-tab-dot aria-hidden="true" class="size-2 rounded-full bg-red-500"></span>
                    <span class="sr-only"> (new)</span>
                <?php endif; ?>
            </button>
        <?php endforeach; ?>
    </div>
</div>
