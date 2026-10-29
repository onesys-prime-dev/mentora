<?php

/*
|--------------------------------------------------------------------------
| Back link — the "< BACK TO ..." above a page
|--------------------------------------------------------------------------
| Params: backUrl (required), backLabel ("Back to dashboard").
|
| The page head draws it above its title, and uses this to do so. A page that
| draws its head inside a panel puts the link above the panel with this, so it
| stays on the background and not in the box. (The parameter names are the page
| head's own, so a page that passes the same names to both gives one answer.)
|--------------------------------------------------------------------------
*/

$backLabel = $backLabel ?? 'Back';
?>
<div class="mb-2 flex items-center gap-4 font-heading text-xs uppercase text-[#3D3D3D]" data-back-link>
    <a class="-ml-1 flex min-h-9 items-center gap-2 rounded px-1 transition hover:text-brand focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/50" href="<?= esc($backUrl) ?>">
        <svg class="h-4 w-4" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M15 18l-6-6 6-6" /></svg>
        <?= esc($backLabel) ?>
    </a>
</div>
