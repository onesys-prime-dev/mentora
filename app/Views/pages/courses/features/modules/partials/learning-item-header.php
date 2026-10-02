<?php 
$userRole = esc($user->getGroups()[0]);
?>

<!-- header -->
<header class="flex flex-col gap-2 py-2">
    
    <span class="text-3xl font-bold text-[#0040A6]">
        <?= esc($currentItem['title']) ?>
    </span>

    <div class="flex items-center gap-2">
        <div class="flex justify-center items-center">
            <?= learning_item_icon($currentItem['item_type'], 'h-4 w-4') ?>
        </div>

        <span class="capitalize text-sm text-[#646464]">
            <?= esc($currentItem['item_type']) ?>

            •

            <?= esc($currentUnit['title']) ?>
        </span>

        <?php if ($userRole === 'student'): ?>
    
            <?php $isCompleted = !empty($currentItem['is_completed']); ?>

            <!-- both rendered; completion.js swaps them after "Mark as Completed" -->
            <span
                data-completion-badge="completed"
                class="px-3 py-1 rounded-full bg-[#E6F4EA] text-[#5C9F72] text-xs font-black <?= $isCompleted ? '' : 'hidden' ?>">
                Completed
            </span>

            <span
                data-completion-badge="pending"
                class="px-3 py-1 rounded-full bg-[#E0E5F2] text-[#002068] text-xs font-black <?= $isCompleted ? 'hidden' : '' ?>">
                <?= !empty($currentLock) ? 'Locked' : 'Not Yet Completed' ?>
            </span>
        
        <?php elseif ($userRole === 'instructor'): ?>

            <span
                class="capitalize rounded-full px-3 py-1 text-xs font-black
                    <?= $currentItem['status'] === 'published'
                        ? 'bg-[#E0E5F2] text-[#002068]'
                        : 'bg-gray-100 text-gray-600' ?>">
                <?= esc($currentItem['status']) ?>
            </span>

        <?php endif; ?>

    </div>

    <div class="flex items-center gap-2">
        <div class="h-8 w-8 flex justify-center items-center rounded-4xl">
            <img
                class="h-full w-auto" 
                src="<?= base_url('assets/images/placeholder/profile.png') ?>" alt="">
        </div>

        <span class="capitalize text-sm text-[#646464]">
            Jalen Brunson  •  June 22, 2026  •  7:30AM 
        </span>
    </div>

</header>