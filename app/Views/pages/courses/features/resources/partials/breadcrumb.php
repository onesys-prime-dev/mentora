<?php $level = $level ?? 'offering'; ?>

<div class="flex items-center gap-2 text-sm" data-resources-breadcrumb>

    <button
        type="button"
        data-resources-crumb-root
        class="font-medium text-[#3D3D3D]/60 transition hover:text-[#0040A6] <?= $level === 'offering' ? 'text-[#0040A6]' : '' ?>">
        Modules
    </button>

    <?php if ($level !== 'offering' && $module): ?>

        <svg class="h-5 w-4 opacity-50" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
            <path d="M9 18l6-6-6-6"/>
        </svg>

        <?php if ($level === 'unit'): ?>
            <button
                type="button"
                data-resources-crumb-module="<?= esc($module['id']) ?>"
                class="font-medium text-[#3D3D3D]/60 transition hover:text-[#0040A6]">
                <?= esc($module['title']) ?>
            </button>
        <?php else: ?>
            <span class="font-medium text-[#0040A6]"><?= esc($module['title']) ?></span>
        <?php endif; ?>

    <?php endif; ?>

    <?php if ($level === 'unit' && $unit): ?>

        <svg class="h-5 w-4 opacity-50" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
            <path d="M9 18l6-6-6-6"/>
        </svg>

        <span class="font-medium text-[#0040A6]"><?= esc($unit['title']) ?></span>

    <?php endif; ?>

</div>
