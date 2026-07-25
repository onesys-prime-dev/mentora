<!-- header -->
<header class="flex flex-col gap-2 py-2">
    
    <span class="text-3xl font-bold text-[#0040A6]">
        <?= esc($currentItem['title']) ?>
    </span>

    <div class="flex items-center gap-2">
        <div class="flex justify-center items-center">
            <img
                class="h-4 w-auto" 
                src="<?= base_url('assets/images/icons/' . esc($currentItem['item_type']) . '.png') ?>" alt="">
        </div>

        <span class="capitalize text-sm text-[#565656]">
            <?= esc($currentItem['item_type']) ?>

            •

            <?= esc($currentUnit['title']) ?>
        </span>

        <?php

            $isCompleted =
                $currentItem['learning_completion']
                &&
                $currentItem['learning_completion']['status'] === 'completed';

        ?>

        <?php if ($isCompleted): ?>

            <span class="px-3 py-1 rounded-full bg-[#E6F4EA] text-[#5C9F72] text-xs font-black">
                Completed
            </span>

        <?php else: ?>

            <span class="px-3 py-1 rounded-full bg-[#E0E5F2] text-[#002068] text-xs font-black">
                Not Yet Completed
            </span>

        <?php endif; ?>
    </div>

    <div class="flex items-center gap-2">
        <div class="h-8 w-8 flex justify-center items-center rounded-4xl">
            <img
                class="h-full w-auto" 
                src="<?= base_url('assets/images/placeholder/profile.png') ?>" alt="">
        </div>

        <span class="capitalize text-sm text-[#565656]">
            Jalen Brunson  •  June 22, 2026  •  7:30AM 
        </span>
    </div>

</header>