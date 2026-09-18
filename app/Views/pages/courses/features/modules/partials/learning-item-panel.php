<aside class="w-80 shrink-0 flex flex-col bg-[#F9F9FC] border-r border-[#9E9E9E]">
    
    <div class="flex flex-col gap-1 p-8 border-b border-[#9E9E9E]">

        <span class="text-xl text-[#0040A6] font-bold">
            Module <?= esc($module['order_index']) ?>
        </span>

        <span class="text-md text-[#878787] font-bold">
            <?= esc($module['title']) ?>
        </span>

        <div class="w-full flex items-center gap-3 mt-2">
            <div class="h-2 w-full flex bg-[#E7E6E6] rounded-4xl">
                <div 
                    class="h-full bg-[#0040A6] rounded-4xl"
                    style="width: <?= esc($module['progress']) ?>%;"></div>
            </div>

            <span class="text-xs text-[#5E5E5E]">
                <?= esc($module['progress']) ?>%
            </span>
        </div>

    </div>

    <div class="flex p-4">

        <div class="w-full flex flex-col gap-4">

            <?php foreach ($module['learning_units'] as $unit): ?>
            
                <!-- unit -->
                <div class="flex flex-col">

                    <!-- header -->
                    <div 
                        class="w-full flex justify-between items-center py-2 cursor-pointer"
                        data-learning-unit="<?= esc($unit['id']) ?>">

                        <span class="text-sm font-bold text-[#565656]/50">
                            <?= esc($unit['title']) ?>
                        </span>

                        <svg
                            xmlns="http://www.w3.org/2000/svg"
                            class="h-2 w-2 fill-[#565656] transition-transform duration-300"
                            viewBox="0 0 10 6">

                            <path d="M0 0h10L5 6 0 0z"/>

                        </svg>

                    </div>

                    <div class="flex flex-col pr-4">

                        <?php foreach ($unit['learning_items'] as $item): 
                            $isCurrent =
                                $item['id'] === $currentItem['id'];

                            $isCompleted =
                                $item['learning_completion']
                                &&
                                $item['learning_completion']['status'] === 'completed';
                        ?>

                            <!-- item -->
                            <div 
                                class="flex gap-3 items-center px-5 py-4 rounded-2xl border-b border-gray-100 cursor-pointer
                                <?= $isCurrent
                                    ? 'bg-[#ECEEF4]'
                                    : 'hover:bg-[#ECEEF4]' ?>"
                                data-learning-item="<?= esc($item['id']) ?>">

                                <div 
                                    class="w-6 h-6 shrink-0 flex justify-center items-center border-2 rounded-[999px]
                                    <?= $isCompleted
                                        ? 'border-[#7DB080]'
                                        : 'border-[#999696]' ?>">

                                    <?php if ($isCompleted): ?>

                                        <img
                                            class="h-2 w-auto" 
                                            src="<?= base_url('assets/images/icons/check-green.png') ?>" alt="">

                                    <?php endif; ?>

                                </div>

                                <div class="flex flex-col gap-[2px]">

                                    <span 
                                        class="text-sm
                                        <?= $isCurrent
                                            ? 'font-semibold text-[#0040A6]'
                                            : '' ?>">
                                        <?= esc($item['title']) ?>
                                    </span>

                                    <div class="flex items-center gap-2">
                                        <div class="flex justify-center items-center">
                                            <img
                                                class="h-3 w-auto" 
                                                src="<?= base_url('assets/images/icons/' . esc($item['item_type']) . '.png') ?>" alt="">
                                        </div>

                                        <span class="capitalize text-xs text-[#565656]">
                                            <?= esc($item['item_type']) ?> • June 22, 2026
                                        </span>
                                    </div>

                                </div>

                            </div>

                        <?php endforeach; ?>

                    </div>

                </div>

            <?php endforeach; ?>

        </div>

    </div>

</aside>