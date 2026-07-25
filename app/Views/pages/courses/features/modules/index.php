<div class="p-10 flex flex-col gap-10">

    <div class="flex justify-between items-center">

        <div class="flex items-center gap-4">
            <div class="flex justify-center items-center">
                <img
                    class="h-5 w-auto" 
                    src="<?= base_url('assets/images/icons/modules.png') ?>" alt="">
            </div>
        
            <span class="text-2xl font-bold text-[#0040A6]">
                Course Modules
            </span>
        </div>

        <div class="flex gap-4">
            <select
                name="filter"
                class="h-10 w-48 appearance-none rounded-full border border-gray-300 bg-white px-4 pr-10 text-sm shadow outline-none focus:border-blue-500">

                <option value="">All</option>
                <option value="active">Active</option>
                <option value="inactive">Inactive</option>
                <option value="archived">Archived</option>

            </select>

            <?= $this->include('components/search') ?>
        </div>

    </div>

    <!-- modules -->
    <div class="flex flex-col gap-5">

        <?php foreach ($modules as $module): ?>

            <!-- module -->
            <div class="flex flex-col bg-white border border-black/10 rounded-2xl">

                <!-- header -->
                <div 
                    class="flex justify-between items-center gap-5 p-6 cursor-pointer"
                    data-module="<?= esc($module['id']) ?>">

                    <div class="flex justify-center items-center">

                        <?php if ($module['progress'] == 100): ?>

                            <div class="w-15 h-15 flex justify-center items-center bg-[#E8F5E9] rounded-[999px]">
                                <img
                                    class="h-5 w-auto" 
                                    src="<?= base_url('assets/images/icons/check-green.png') ?>" alt="">
                            </div>

                        <?php elseif ($module['progress'] < 100 && $module['progress'] > 0): ?>
                            
                            <div class="w-15 h-15 flex justify-center items-center bg-[#E0E5F2] rounded-[999px]">
                                <img
                                    class="h-8 w-auto" 
                                    src="<?= base_url('assets/images/icons/in-progress.png') ?>" alt="">
                            </div>

                        <?php elseif ($module['progress'] == 0): ?>

                            <div class="w-15 h-15 flex justify-center items-center bg-[#E0E5F2] rounded-[999px]">
                                <img
                                    class="h-8 w-auto" 
                                    src="<?= base_url('assets/images/icons/in-progress.png') ?>" alt="">
                            </div>

                        <?php endif; ?>

                    </div>

                    <div class="flex flex-col flex-1">

                        <span class="uppercase text-sm font-semibold text-[#878787]">
                            MODULE <?= esc($module['order_index']) ?>
                        </span>

                        <div class="flex justify-between">
                            <span class="text-2xl font-bold text-[#0040A6]">
                                <?= esc($module['title']) ?>
                            </span>

                            <?php if ($module['progress'] == 100): ?>

                                <span class="px-6 py-2 bg-[#E6F4EA] uppercase text-xs font-black text-[#5C9F72] rounded-4xl">
                                    PROGRESS <?= esc($module['progress']) ?>%
                                </span>

                            <?php elseif ($module['progress'] < 100 && $module['progress'] > 0): ?>

                                <span class="px-6 py-2 bg-[#E0E5F2] uppercase text-xs font-black text-[#002068] rounded-4xl">
                                    PROGRESS <?= esc($module['progress']) ?>%
                                </span>

                            <?php elseif ($module['progress'] == 0): ?>

                                <span class="px-6 py-2 bg-[#E9E9EC] uppercase text-xs font-black text-[#8F9198] rounded-4xl">
                                    NOT YET STARTED
                                </span>
                            
                            <?php endif; ?>
                        </div>

                        <div class="h-2 w-full mt-4 flex bg-[#E7E6E6] rounded-4xl">
                            <div 
                                class="h-full bg-[#0040A6] rounded-4xl"
                                style="width: <?= esc($module['progress']) ?>%;"></div>
                        </div>

                    </div>

                    <div class="flex justify-center items-center px-5">
                        <svg
                            xmlns="http://www.w3.org/2000/svg"
                            class="h-5 w-5 fill-[#002068] transition-transform duration-300"
                            data-module-arrow="<?= esc($module['id']) ?>"
                            viewBox="0 0 10 6">

                            <path d="M0 0h10L5 6 0 0z"/>

                        </svg>
                    </div>

                </div>

                <!-- contents -->
                <div 
                    class="max-h-0 overflow-hidden opacity-0 transition-all duration-300 ease-in-out px-25"
                    data-module-content="<?= esc($module['id']) ?>">

                    <div class="mb-6">
                        <span class="text-lg text-[#565656]">
                            <?= esc($module['description']) ?>
                        </span>
                    </div>

                    <?php foreach ($module['learning_units'] as $learning_unit): ?>

                        <!-- unit -->
                        <div class="flex flex-col">

                            <div class="flex justify-between items-center">
                                <div class="flex items-center gap-4">
                                    <div class="w-7 flex justify-center items-center">
                                        <img
                                            class="" 
                                            src="<?= base_url('assets/images/icons/learning-unit.png') ?>" alt="">
                                    </div>

                                    <span class="text-lg text-[#1A1C1E]">
                                        Unit <?= esc($module['order_index']) ?>.<?= esc($learning_unit['order_index']) ?>: <?= esc($learning_unit['title']) ?>
                                    </span>
                                </div>

                            </div>

                            <div class="flex gap-4 py-3">

                                <div class="w-7 flex justify-center">
                                    <div class="w-[2px] bg-[#C7C7C7]"></div>
                                </div>

                                <!-- items -->
                                <div class="w-full flex flex-col pb-4">

                                    <?php foreach ($learning_unit['learning_items'] as $learning_item): ?>

                                        <!-- item -->
                                        <div 
                                            class="w-full flex items-center gap-4 px-6 py-4 rounded-2xl border-b border-gray-100 cursor-pointer hover:bg-[#ECEEF4]"
                                            data-learning-item="<?= esc($learning_item['id']) ?>">

                                            <div class="flex justify-center items-center px-2">

                                                <?php if ($learning_item['is_completed']): ?>

                                                    <div class="w-6 h-6 flex justify-center items-center border-2 border-[#7DB080] rounded-[999px]">
                                                        <img
                                                            class="h-2 w-auto" 
                                                            src="<?= base_url('assets/images/icons/check-green.png') ?>" alt="">
                                                    </div>

                                                <?php else: ?>

                                                    <div class="w-6 h-6 flex justify-center items-center border-2 border-[#999696] rounded-[999px]">
                                                
                                                    </div>

                                                <?php endif; ?>   

                                            </div>

                                            <div class="w-full flex justify-between items-center">
                                                <div class="flex flex-col gap-1">
                                                    <span class="text-md">
                                                        <?= esc($learning_item['title']) ?>
                                                    </span>

                                                    <div class="flex items-center gap-2">
                                                        <div class="flex justify-center items-center">
                                                            <img
                                                                class="h-4 w-auto" 
                                                                src="<?= base_url('assets/images/icons/reading.png') ?>" alt="">
                                                        </div>

                                                        <span class="capitalize text-sm text-[#565656]">
                                                            <?= esc($learning_item['item_type']) ?>
                                                        </span>
                                                    </div>
                                                </div>

                                                <div class="flex justify-center items-center">
                                                    
                                                </div>
                                            </div>

                                        </div>

                                    <?php endforeach; ?>

                                </div>
                            
                            </div>

                        </div>

                    <?php endforeach; ?>

                </div>

            </div>

        <?php endforeach; ?>

    </div>

</div>