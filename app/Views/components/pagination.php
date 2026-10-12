<?php

/*
|--------------------------------------------------------------------------
| Pagination — "Page 2 of 7" with Previous and Next
|--------------------------------------------------------------------------
| Params: page, pages (both required), baseUrl (required), query (the other
| filters, kept in every link), total (optional, shown as "N in all").
| Renders nothing when there is one page or none.
|--------------------------------------------------------------------------
*/

$query = $query ?? [];
$total = $total ?? null;

if ($pages <= 1) {
    return;
}

$link = static fn (int $n): string => $baseUrl . '?' . http_build_query(array_merge($query, ['page' => $n]));
?>

<nav class="flex flex-wrap items-center justify-between gap-3 text-sm" aria-label="Pages">

    <span class="text-[#646464]">
        Page <?= esc((string) $page) ?> of <?= esc((string) $pages) ?><?= $total !== null ? ' · ' . esc(number_format((int) $total)) . ' in all' : '' ?>
    </span>

    <span class="flex gap-2">
        <?php if ($page > 1): ?>
            <a class="rounded-full border border-brand px-4 py-1.5 font-semibold text-brand transition hover:bg-brand/10" href="<?= esc($link($page - 1)) ?>" rel="prev">Previous</a>
        <?php endif; ?>
        <?php if ($page < $pages): ?>
            <a class="rounded-full border border-brand px-4 py-1.5 font-semibold text-brand transition hover:bg-brand/10" href="<?= esc($link($page + 1)) ?>" rel="next">Next</a>
        <?php endif; ?>
    </span>

</nav>
