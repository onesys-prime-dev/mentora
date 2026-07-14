<?php 
$userRole = esc($user->getGroups()[0]);
?>

<div class="flex gap-4 items-center mb-5 uppercase font-heading text-xs opacity-50">

    <a 
        class="flex items-center gap-2"
        href="<?= site_url($userRole . '/' . $previousPage) ?>">

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