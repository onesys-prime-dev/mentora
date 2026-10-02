<div class="flex items-center gap-2">

    <button
        type="button"
        class="text-sm font-medium text-[#3D3D3D]/60 transition cursor-pointer hover:text-[#0040A6]"
        data-modules-breadcrumb
    >
        Modules
    </button>

    <svg
        class="h-6 w-4 opacity-50"
        xmlns="http://www.w3.org/2000/svg"
        viewBox="0 0 24 24"
        fill="none"
        stroke="currentColor"
        stroke-width="2.5"
        stroke-linecap="round"
        stroke-linejoin="round"
    >
        <path d="M9 18l6-6-6-6" />
    </svg>

    <button
        type="button"
        class="text-sm font-medium text-[#3D3D3D]/60 transition cursor-pointer hover:text-[#0040A6]"
        data-module-breadcrumb
        data-module-id="<?= esc($module['id']) ?>"
    >
        <?= esc($module['title']) ?>
    </button>

    <svg
        class="h-6 w-4 opacity-50"
        xmlns="http://www.w3.org/2000/svg"
        viewBox="0 0 24 24"
        fill="none"
        stroke="currentColor"
        stroke-width="2.5"
        stroke-linecap="round"
        stroke-linejoin="round"
    >
        <path d="M9 18l6-6-6-6" />
    </svg>

    <span class="text-sm text-[#3D3D3D]/60">
        <?= esc($currentUnit['title']) ?>
    </span>

    <svg
        class="h-6 w-4 opacity-50"
        xmlns="http://www.w3.org/2000/svg"
        viewBox="0 0 24 24"
        fill="none"
        stroke="currentColor"
        stroke-width="2.5"
        stroke-linecap="round"
        stroke-linejoin="round"
    >
        <path d="M9 18l6-6-6-6" />
    </svg>

    <span class="text-sm text-[#3D3D3D]/60">
        <?= esc($currentItem['title']) ?>
    </span>

</div>