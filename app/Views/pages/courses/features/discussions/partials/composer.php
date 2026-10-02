<?php
/*
|--------------------------------------------------------------------------
| Discussion composer — hidden until opened. One markup serves both a new
| discussion and editing the opening post of an existing one.
|--------------------------------------------------------------------------
*/
?>

<div class="hidden flex-col rounded-2xl border border-gray-200 bg-white shadow-sm" data-discussion-composer>

    <div class="flex items-center justify-between px-5 py-4">
        <h2 class="text-lg font-bold text-[#0040A6]" data-composer-heading>New discussion</h2>
        <button type="button" data-composer-cancel aria-label="Close" class="rounded-lg p-1.5 text-[#646464] transition hover:bg-gray-100">
            <svg class="h-5 w-5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
        </button>
    </div>

    <div class="border-t border-gray-100 px-5 py-3">
        <input
            type="text"
            data-composer-title
            maxlength="255"
            aria-label="Discussion title"
            placeholder="Title"
            autocomplete="off"
            class="w-full text-lg font-bold text-[#0040A6] outline-none placeholder:font-bold placeholder:text-gray-400">
    </div>

    <div class="flex items-center gap-1 border-t border-gray-100 px-5 py-2" data-composer-toolbar>
        <button type="button" data-action="bold" title="Bold" aria-label="Bold" class="rounded-lg p-1.5 text-gray-500 hover:bg-gray-100"><svg class="h-4 w-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M6 4h8a3.5 3.5 0 010 7H6zM6 11h9a3.5 3.5 0 010 7H6z"/></svg></button>
        <button type="button" data-action="italic" title="Italic" aria-label="Italic" class="rounded-lg p-1.5 text-gray-500 hover:bg-gray-100"><svg class="h-4 w-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M10 4h8M6 20h8M13 4L9 20"/></svg></button>
        <button type="button" data-action="underline" title="Underline" aria-label="Underline" class="rounded-lg p-1.5 text-gray-500 hover:bg-gray-100"><svg class="h-4 w-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 4v7a6 6 0 0012 0V4M4 20h16"/></svg></button>
        <span class="mx-1 h-5 w-px bg-gray-200"></span>
        <button type="button" data-action="insertUnorderedList" title="Bulleted list" aria-label="Bulleted list" class="rounded-lg p-1.5 text-gray-500 hover:bg-gray-100"><svg class="h-4 w-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M8 6h13M8 12h13M8 18h13M3 6h.01M3 12h.01M3 18h.01"/></svg></button>
        <button type="button" data-action="insertOrderedList" title="Numbered list" aria-label="Numbered list" class="rounded-lg p-1.5 text-gray-500 hover:bg-gray-100"><svg class="h-4 w-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M8 6h13M8 12h13M8 18h13M4 6h1v2M4 10h2l-2 2.5h2M4.5 16h1.5a.75.75 0 010 1.5H5m1 0a.75.75 0 010 1.5H4"/></svg></button>
    </div>

    <div class="px-5 py-3" id="discussion-editor" data-message="{}"></div>

    <div class="flex flex-wrap gap-2 px-5" data-composer-attachments></div>

    <div class="flex items-center justify-between border-t border-gray-100 px-5 py-3">

        <div>
            <button type="button" data-composer-attach title="Attach files" aria-label="Attach files" class="rounded-lg p-2 text-[#646464] transition hover:bg-gray-100">
                <?= icon('link', 'h-5 w-5 text-current') ?>
            </button>
            <input type="file" data-composer-file-input class="hidden" multiple accept=".png,.jpg,.jpeg,.gif,.webp,.pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx">
        </div>

        <div class="flex items-center gap-2">
            <button type="button" data-composer-cancel class="rounded-full px-4 py-2 text-sm font-semibold text-[#646464] transition hover:bg-gray-100">Cancel</button>
            <button type="button" data-composer-submit disabled class="rounded-full bg-[#0040A6] px-6 py-2 text-sm font-semibold text-white transition hover:bg-[#002068] disabled:opacity-50">Post</button>
        </div>

    </div>

</div>
