<?php
$userRole = esc($user->getGroups()[0]);

// A page can give the exact address to go back to (a parent returns to one child's dashboard).
// Not named backUrl: that is the page-head component's own parameter, and view data is shared.
$backUrl = $courseBackUrl ?? site_url($userRole . '/' . $previousPage);
?>

<div class="flex gap-4 items-center mb-2 uppercase font-heading text-xs text-[#3D3D3D]">

    <a 
        class="-ml-1 flex min-h-9 items-center gap-2 rounded px-1 text-[#3D3D3D] transition hover:text-brand focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/50"
        href="<?= esc($backUrl) ?>">

        <svg
            class="h-4 w-4"
            xmlns="http://www.w3.org/2000/svg"
            viewBox="0 0 24 24"
            fill="none"
            stroke="currentColor"
            stroke-width="2.5"
            stroke-linecap="round"
            stroke-linejoin="round"
        >
            <path d="M15 18l-6-6 6-6" />
        </svg>

        Back to <?= esc($previousPage) ?>
    </a>

</div>