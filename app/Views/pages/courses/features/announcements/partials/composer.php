<?php
$profile = current_user_profile($user);
$fullName = trim(($profile['first_name'] ?? '') . ' ' . ($profile['last_name'] ?? ''));
?>

<div class="flex flex-col rounded-2xl border border-gray-200 bg-white shadow-sm" data-announcement-composer data-mode="create" data-announcement-id="">

    <div class="flex items-center justify-between px-5 py-4">
        <div class="flex items-center gap-3">
            <img src="<?= base_url('assets/images/placeholder/profile.png') ?>" class="h-9 w-9 rounded-full" alt="">
            <span class="font-semibold text-[#1A1C1E]"><?= esc($fullName) ?></span>
        </div>

        <button type="button" data-composer-dismiss title="Discard" class="rounded-lg p-1.5 text-[#767676] transition hover:bg-gray-100 hover:text-gray-600" aria-label="Discard">
            <svg data-composer-close-icon xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
            </svg>
            <svg data-composer-trash-icon class="hidden h-5 w-5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M19 7L18.133 19.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6M9 7V4a1 1 0 011-1h4a1 1 0 011 1v3m-7 0h8"/>
            </svg>
        </button>
    </div>

    <div class="border-t border-gray-100 px-5 py-3">
        <input
            type="text"
            data-composer-subject
            placeholder="Add a subject"
            autocomplete="off"
            class="w-full text-lg font-bold text-brand placeholder:text-gray-300 placeholder:font-bold outline-none">
    </div>

    <div class="border-t border-gray-100 px-5 py-2 flex items-center gap-1" data-composer-toolbar>

        <button type="button" data-action="bold" title="Bold" class="rounded-lg p-1.5 text-gray-500 hover:bg-gray-100" aria-label="Bold"><svg class="h-4 w-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M6 4h8a3.5 3.5 0 010 7H6zM6 11h9a3.5 3.5 0 010 7H6z"/></svg></button>
        <button type="button" data-action="italic" title="Italic" class="rounded-lg p-1.5 text-gray-500 hover:bg-gray-100" aria-label="Italic"><svg class="h-4 w-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M10 4h8M6 20h8M13 4L9 20"/></svg></button>
        <button type="button" data-action="underline" title="Underline" class="rounded-lg p-1.5 text-gray-500 hover:bg-gray-100" aria-label="Underline"><svg class="h-4 w-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 4v7a6 6 0 0012 0V4M4 20h16"/></svg></button>
        <span class="mx-1 h-5 w-px bg-gray-200"></span>
        <button type="button" data-action="insertUnorderedList" title="Bulleted list" class="rounded-lg p-1.5 text-gray-500 hover:bg-gray-100" aria-label="Bulleted list"><svg class="h-4 w-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M8 6h13M8 12h13M8 18h13M3 6h.01M3 12h.01M3 18h.01"/></svg></button>
        <button type="button" data-action="insertOrderedList" title="Numbered list" class="rounded-lg p-1.5 text-gray-500 hover:bg-gray-100" aria-label="Numbered list"><svg class="h-4 w-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M8 6h13M8 12h13M8 18h13M4 6h1v2M4 10h2l-2 2.5h2M4.5 16h1.5a.75.75 0 010 1.5H5m1 0a.75.75 0 010 1.5H4"/></svg></button>

    </div>

    <div class="px-5 py-3" id="announcement-editor" data-message="{}"></div>

    <div class="flex flex-wrap gap-2 px-5" data-composer-attachments></div>

    <div class="flex items-center justify-between px-5 py-3 border-t border-gray-100">

        <div class="flex items-center gap-1">

            <button type="button" data-composer-emoji-toggle title="Emoji" class="rounded-lg p-2 text-gray-500 hover:bg-gray-100" aria-label="Emoji">
                <svg class="h-5 w-5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9.172 16.172a4 4 0 015.656 0M9 10h.01M15 10h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
            </button>

            <button type="button" data-composer-attach title="Attach files" class="rounded-lg p-2 text-gray-500 hover:bg-gray-100" aria-label="Attach files">
                <svg class="h-5 w-5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1"/></svg>
            </button>
            <input type="file" data-composer-file-input class="hidden" multiple accept=".png,.jpg,.jpeg,.gif,.webp,.pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx">

            <div class="relative" data-composer-emoji-panel-wrapper>
                <div class="hidden absolute left-0 bottom-full z-30 mb-2" data-composer-emoji-panel data-action-menu-panel></div>
            </div>

        </div>

        <button
            type="button"
            data-composer-submit
            class="rounded-full bg-brand px-6 py-2 text-sm font-semibold text-white transition hover:bg-brand-dark disabled:opacity-50"
            disabled>
            Post
        </button>

    </div>

</div>
