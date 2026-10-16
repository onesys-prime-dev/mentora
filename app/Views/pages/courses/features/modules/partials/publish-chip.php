<?php
/*
|--------------------------------------------------------------------------
| Publish state chip for a module, learning unit or learning item (instructor
| views). What a student can open depends on the parents too, so a published
| child of an unpublished parent says it is hidden rather than "Published".
|
| Params: status ('published'|'draft'), hiddenBy ('module'|'unit'|null)
|--------------------------------------------------------------------------
*/
$chipPublished = ($status ?? 'draft') === 'published';
$chipHiddenBy = $chipPublished ? ($hiddenBy ?? null) : null;
?>
<?php if ($chipHiddenBy !== null): ?>
    <span
        title="This is published, but students cannot see it until its <?= esc($chipHiddenBy) ?> is published."
        class="inline-flex shrink-0 items-center gap-1.5 rounded-full bg-[#FFF1D6] px-2 py-0.5 text-[10px] font-medium text-[#946200]"
        data-publish-chip="hidden">
        <span class="h-1.5 w-1.5 rounded-full bg-[#946200]"></span>
        Hidden · <?= esc($chipHiddenBy) ?> is a draft
    </span>
<?php elseif ($chipPublished): ?>
    <span class="inline-flex shrink-0 items-center gap-1.5 rounded-full bg-brand/10 px-2 py-0.5 text-[10px] font-medium text-brand" data-publish-chip="published">
        <span class="h-1.5 w-1.5 rounded-full bg-brand"></span>
        Published
    </span>
<?php else: ?>
    <span class="inline-flex shrink-0 items-center gap-1.5 rounded-full bg-gray-100 px-2 py-0.5 text-[10px] font-medium text-gray-500" data-publish-chip="draft">
        <span class="h-1.5 w-1.5 rounded-full bg-gray-400"></span>
        Draft
    </span>
<?php endif; ?>
