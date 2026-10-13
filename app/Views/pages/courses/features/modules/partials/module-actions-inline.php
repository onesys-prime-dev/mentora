<?php
/**
 * module.php's top bar has room for real icon buttons instead of a kebab —
 * same data attributes the existing JS already binds against
 * (modals/modules.js, publish-toggle.js), just different markup.
 */
$isPublished = $module['status'] === 'published';
?>

<div class="flex items-center gap-2">

    <button
        type="button"
        title="<?= $isPublished ? 'Unpublish module' : 'Publish module' ?>"
        aria-label="<?= $isPublished ? 'Unpublish module' : 'Publish module' ?>"
        class="flex items-center gap-2 rounded-full border border-gray-200 px-4 py-2 text-sm font-semibold transition <?= $isPublished ? 'text-[#767676] hover:bg-gray-100' : 'text-brand hover:bg-brand/5' ?>"
        data-module-publish-toggle
        data-module-id="<?= esc($module['id']) ?>"
        data-status="<?= esc($module['status']) ?>">

        <?php if ($isPublished): ?>
            <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636m12.728 12.728L5.636 5.636"/>
            </svg>
            <span data-publish-toggle-label>Unpublish Module</span>
        <?php else: ?>
            <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/>
            </svg>
            <span data-publish-toggle-label>Publish Module</span>
        <?php endif; ?>

    </button>

    <button
        type="button"
        title="Edit module"
        aria-label="Edit module"
        class="flex h-10 w-10 items-center justify-center rounded-full text-gray-500 transition hover:bg-gray-100 hover:text-brand"
        data-edit-module
        data-module-id="<?= esc($module['id']) ?>"
        data-module-title="<?= esc($module['title']) ?>"
        data-module-description="<?= esc($module['description']) ?>">
        <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5M16.5 3.5a2.121 2.121 0 113 3L12 14l-4 1 1-4 7.5-7.5z"/>
        </svg>
    </button>

    <button
        type="button"
        title="Delete module"
        aria-label="Delete module"
        class="flex h-10 w-10 items-center justify-center rounded-full text-gray-500 transition hover:bg-red-50 hover:text-red-600"
        data-delete-module
        data-module-id="<?= esc($module['id']) ?>">
        <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M19 7L18.133 19.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6M9 7V4a1 1 0 011-1h4a1 1 0 011 1v3m-7 0h8"/>
        </svg>
    </button>

</div>
