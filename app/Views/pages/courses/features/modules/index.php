<?php 
$userRole = esc($user->getGroups()[0]);
?>

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

            <?php if ($userRole === 'instructor'): ?>

                <button
                    class="inline-flex items-center gap-2 rounded-4xl bg-[#0040A6] px-5 py-2 text-sm font-semibold text-white shadow transition-colors duration-300 hover:bg-[#002068]"
                    data-create-module-button>

                    <svg
                        xmlns="http://www.w3.org/2000/svg"
                        class="h-5 w-5"
                        fill="none"
                        viewBox="0 0 24 24"
                        stroke="currentColor"
                        stroke-width="2">

                        <path
                            stroke-linecap="round"
                            stroke-linejoin="round"
                            d="M12 5v14M5 12h14" />

                    </svg>

                    <span>Add Module</span>

                </button>

            <?php endif; ?>

        </div>

    </div>

    <!-- modules -->
    <div 
        id="modules-container"
        class="flex flex-col gap-5">

        <?php foreach ($modules as $module): ?>

            <!-- module -->
            <div
                class="flex items-center p-1"
                data-module-wrapper
                data-module-id="<?= esc($module['id']) ?>">

                <?php if ($userRole === 'instructor'): ?>

                    <div
                        data-module-card-drag-handle
                        class="flex h-8 w-8 pl-1 cursor-grab items-center text-[#1C2431] active:cursor-grabbing">

                        <svg
                            xmlns="http://www.w3.org/2000/svg"
                            class="h-5 w-4"
                            viewBox="0 0 16 24"
                            fill="currentColor">

                            <circle cx="4" cy="4" r="2"/>
                            <circle cx="12" cy="4" r="2"/>

                            <circle cx="4" cy="12" r="2"/>
                            <circle cx="12" cy="12" r="2"/>

                            <circle cx="4" cy="20" r="2"/>
                            <circle cx="12" cy="20" r="2"/>

                        </svg>

                    </div>

                <?php endif; ?>

                <div 
                    class="flex flex-col flex-1 bg-white border border-black/10 rounded-2xl"
                    data-module-card>

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

                            <div class="flex flex-1 justify-between items-center">

                                <div class="flex flex-col flex-1">
                                    <span
                                        class="uppercase text-sm font-semibold text-[#878787]"
                                        data-module-order>
                                        MODULE <?= esc($module['order_index']) ?>
                                    </span>

                                    <span class="text-2xl font-bold text-[#0040A6]">
                                        <?= esc($module['title']) ?>
                                    </span>

                                </div>

                                <?php if ($userRole === 'instructor'): ?>

                                    <button
                                        type="button"
                                        data-module-status-toggle
                                        data-status="<?= esc($module['status']) ?>"
                                        class="relative inline-flex h-8 w-24 mr-2 items-center rounded-full px-1 transition-all duration-200
                                            <?= $module['status'] === 'published'
                                                ? 'bg-[#0040A6]'
                                                : 'bg-gray-300' ?>">

                                        <span
                                            data-module-status-knob
                                            class="absolute h-6 w-11 rounded-full bg-white shadow transition-all duration-200
                                                <?= $module['status'] === 'published'
                                                    ? 'translate-x-11'
                                                    : '' ?>">
                                        </span>

                                        <!-- Label -->
                                        <span
                                            data-module-status-label
                                            class="absolute inset-0 z-10 flex items-center px-3 text-[10px] font-bold uppercase tracking-wide transition-all duration-200
                                                <?= $module['status'] === 'published'
                                                    ? 'justify-start text-white'
                                                    : 'justify-end text-gray-700/50' ?>">

                                            <?= $module['status'] === 'draft'
                                                ? 'Draft'
                                                : '' ?>

                                        </span>

                                    </button>
                                
                                <?php else: ?>

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

                                <?php endif; ?>

                            </div>

                            <?php if ($userRole === 'student'): ?>

                                <div class="h-2 w-full mt-4 flex bg-[#E7E6E6] rounded-4xl">
                                    <div 
                                        class="h-full bg-[#0040A6] rounded-4xl"
                                        style="width: <?= esc($module['progress']) ?>%;"></div>
                                </div>

                            <?php endif; ?>

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

                        <?php if ($userRole === 'instructor'): ?>

                            <?= view(
                                'pages/courses/features/modules/partials/module-actions',
                                [
                                    'module' => $module,
                                ]
                            ) ?>

                        <?php endif; ?>

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
                            <div 
                                class="flex items-center p-1 py-3"
                                data-learning-unit-row>

                                <?php if ($userRole === 'instructor'): ?>

                                    <div
                                        data-learning-unit-drag-handle
                                        class="flex h-8 w-8 pl-1 cursor-grab items-center text-[#1C2431] active:cursor-grabbing">

                                        <svg
                                            xmlns="http://www.w3.org/2000/svg"
                                            class="h-5 w-4"
                                            viewBox="0 0 16 24"
                                            fill="currentColor">

                                            <circle cx="4" cy="4" r="2"/>
                                            <circle cx="12" cy="4" r="2"/>

                                            <circle cx="4" cy="12" r="2"/>
                                            <circle cx="12" cy="12" r="2"/>

                                            <circle cx="4" cy="20" r="2"/>
                                            <circle cx="12" cy="20" r="2"/>

                                        </svg>

                                    </div>
                                
                                <?php endif; ?>

                                <div
                                    class="flex flex-col flex-1"
                                    data-learning-unit-wrapper
                                    data-learning-unit-id="<?= esc($learning_unit['id']) ?>">

                                    <div
                                        class="flex justify-between items-center cursor-pointer"
                                        data-learning-unit="<?= esc($learning_unit['id']) ?>">

                                        <div class="flex items-center gap-4">

                                            <div class="w-10 flex justify-center items-center">
                                                <img
                                                    class="" 
                                                    src="<?= base_url('assets/images/icons/learning-unit.png') ?>" alt="">
                                            </div>

                                            <span
                                                class="text-lg text-[#1A1C1E]"
                                                data-learning-unit-order>

                                                Unit
                                                <?= esc($module['order_index']) ?>.<?= esc($learning_unit['order_index']) ?>:
                                                <?= esc($learning_unit['title']) ?>

                                            </span>

                                        </div>

                                        <div class="flex items-center gap-2">

                                            <?php if ($userRole === 'instructor'): ?>

                                                <button
                                                    type="button"
                                                    data-learning-unit-status-toggle
                                                    data-status="<?= esc($learning_unit['status']) ?>"
                                                    class="relative inline-flex h-6 w-[4.7rem] mr-4 items-center rounded-full px-1 transition-all duration-200
                                                        <?= $learning_unit['status'] === 'published'
                                                            ? 'bg-[#0040A6]'
                                                            : 'bg-gray-300' ?>">

                                                    <span
                                                        data-learning-unit-status-knob
                                                        class="absolute left-1 h-4 w-[1.875rem] rounded-full bg-white shadow transition-all duration-200
                                                            <?= $learning_unit['status'] === 'published'
                                                                ? 'translate-x-[2.25rem]'
                                                                : '' ?>">
                                                    </span>

                                                    <span
                                                        data-learning-unit-status-label
                                                        class="absolute inset-0 z-10 flex items-center px-2 text-[9px] font-bold uppercase tracking-wide transition-all duration-200
                                                            <?= $learning_unit['status'] === 'published'
                                                                ? 'justify-start text-white'
                                                                : 'justify-end text-gray-700/50' ?>">

                                                        <?= $learning_unit['status'] === 'draft'
                                                            ? 'Draft'
                                                            : '' ?>

                                                    </span>

                                                </button>
                                            
                                            <?php endif; ?>

                                            <div class="h-10 w-10 flex items-center justify-center">

                                                <svg
                                                    xmlns="http://www.w3.org/2000/svg"
                                                    class="h-4 w-4 fill-[#002068] transition-transform duration-300"
                                                    viewBox="0 0 10 6"
                                                    data-learning-unit-arrow="<?= esc($learning_unit['id']) ?>">

                                                    <path d="M0 0h10L5 6 0 0z"/>

                                                </svg>

                                            </div>

                                            <?php if ($userRole === 'instructor'): ?>

                                                <?= view(
                                                    'pages/courses/features/modules/partials/learning-unit-actions',
                                                    [
                                                        'learning_unit' => $learning_unit,
                                                    ]
                                                ) ?>

                                            <?php endif; ?>
                                        
                                        </div>

                                    </div>

                                    <div
                                        data-learning-unit-content="<?= esc($learning_unit['id']) ?>"
                                        class="flex max-h-0 overflow-hidden opacity-0 transition-all duration-300">

                                        <div class="w-10 flex justify-center">
                                            <div class="w-[2px] bg-[#C7C7C7]"></div>
                                        </div>

                                        <!-- items -->
                                        <div
                                            class="w-full flex flex-col py-4 pr-4"
                                            data-learning-unit-items>

                                            <?php foreach ($learning_unit['learning_items'] as $learning_item): ?>

                                                <!-- item -->
                                                <div
                                                    class="flex items-center p-1"
                                                    data-learning-item-row>

                                                    <div
                                                        class="group w-full flex items-center gap-4 px-6 py-4 rounded-2xl cursor-pointer hover:bg-[#ECEEF4]"
                                                        data-learning-item-wrapper
                                                        data-learning-item="<?= esc($learning_item['id']) ?>">

                                                        <?php if ($userRole === 'instructor'): ?>
                                                    
                                                            <div
                                                                data-learning-item-drag-handle
                                                                class="flex h-8 w-8 pl-1 cursor-grab items-center text-[#1C2431] active:cursor-grabbing">

                                                                <svg
                                                                    xmlns="http://www.w3.org/2000/svg"
                                                                    class="h-5 w-4"
                                                                    viewBox="0 0 16 24"
                                                                    fill="currentColor">

                                                                    <circle cx="4" cy="4" r="2"/>
                                                                    <circle cx="12" cy="4" r="2"/>

                                                                    <circle cx="4" cy="12" r="2"/>
                                                                    <circle cx="12" cy="12" r="2"/>

                                                                    <circle cx="4" cy="20" r="2"/>
                                                                    <circle cx="12" cy="20" r="2"/>

                                                                </svg>

                                                            </div>

                                                        <?php endif; ?>

                                                        <?php if ($userRole === 'student'): ?>

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

                                                        <?php endif; ?>

                                                        <div class="w-full flex justify-between items-center">
                                                            <div class="flex flex-col gap-1">

                                                                <div class="flex items-center gap-4">
                                                                    <span class="text-md">
                                                                        <?= esc($learning_item['title']) ?>
                                                                    </span>

                                                                    <?php if ($userRole === 'instructor'): ?>

                                                                        <?php if ($learning_item['status'] === 'published'): ?>

                                                                            <span
                                                                                class="inline-flex items-center gap-1.5 rounded-full bg-[#0040A6]/10 px-2 py-0.5 text-[10px] font-medium text-[#0040A6]">

                                                                                <span
                                                                                    class="h-1.5 w-1.5 rounded-full bg-[#0040A6]">
                                                                                </span>

                                                                                Published

                                                                            </span>

                                                                        <?php endif; ?>

                                                                    <?php endif; ?>

                                                                </div>

                                                                <div class="flex items-center gap-2">
                                                                    <div class="flex justify-center items-center">
                                                                        <img
                                                                            class="h-4 w-auto" 
                                                                            src="<?= base_url('assets/images/icons/reading.png') ?>" alt="">
                                                                    </div>

                                                                    <span class="text-sm text-[#565656]">
                                                                        <?= $learning_item['item_type'] === 'pdf'
                                                                            ? 'PDF'
                                                                            : esc(ucfirst($learning_item['item_type'])) ?>
                                                                    </span>
                                                                </div>
                                                            </div>

                                                            <div class="flex justify-center items-center">
                                                                
                                                            </div>
                                                        </div>

                                                        <?php if ($userRole === 'instructor'): ?>

                                                            <div class="flex items-center">

                                                                <button
                                                                    type="button"
                                                                    data-learning-item-edit-button
                                                                    data-learning-item="<?= esc($learning_item['id']) ?>"
                                                                    class="inline-flex items-center justify-center p-1 text-gray-400 opacity-0 transition-all duration-200 group-hover:opacity-100 hover:text-[#0040A6] focus:outline-none cursor-pointer">

                                                                    <svg
                                                                        xmlns="http://www.w3.org/2000/svg"
                                                                        class="h-6 w-6 transition-transform duration-200 hover:scale-110"
                                                                        fill="none"
                                                                        viewBox="0 0 24 24"
                                                                        stroke="currentColor"
                                                                        stroke-width="2">

                                                                        <path
                                                                            stroke-linecap="round"
                                                                            stroke-linejoin="round"
                                                                            d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5M16.5 3.5a2.121 2.121 0 113 3L12 14l-4 1 1-4 7.5-7.5z"/>

                                                                    </svg>

                                                                </button>

                                                            </div>

                                                        <?php endif; ?>

                                                    </div>

                                                </div>

                                            <?php endforeach; ?>

                                            <?php if ($userRole === 'instructor'): ?>

                                                <div
                                                    data-create-learning-item-button
                                                    data-module-id="<?= esc($module['id']) ?>"
                                                    data-learning-unit-id="<?= esc($learning_unit['id']) ?>"
                                                    class="group flex cursor-pointer items-center gap-3 px-2 py-5 transition-all duration-200">

                                                    <div
                                                        class="h-px flex-1 rounded-full bg-gray-200 transition-all duration-200 group-hover:bg-[#0040A6]/40">
                                                    </div>

                                                    <div
                                                        class="flex h-11 w-11 items-center justify-center rounded-full border-2 border-dashed border-gray-300 bg-white shadow-sm transition-all duration-200 group-hover:scale-110 group-hover:border-[#0040A6] group-hover:bg-[#0040A6]">

                                                        <svg
                                                            xmlns="http://www.w3.org/2000/svg"
                                                            class="h-5 w-5 text-gray-400 transition-all duration-200 group-hover:rotate-90 group-hover:text-white"
                                                            fill="none"
                                                            viewBox="0 0 24 24"
                                                            stroke="currentColor"
                                                            stroke-width="2">

                                                            <path
                                                                stroke-linecap="round"
                                                                stroke-linejoin="round"
                                                                d="M12 5v14M5 12h14"/>

                                                        </svg>

                                                    </div>

                                                    <span
                                                        class="max-w-0 overflow-hidden whitespace-nowrap text-sm font-medium text-[#0040A6]
                                                            opacity-0 transition-all duration-200
                                                            group-hover:max-w-[180px] group-hover:opacity-100">
                                                        Add Learning Item
                                                    </span>

                                                    <div
                                                        class="h-px flex-1 rounded-full bg-gray-200 transition-all duration-200 group-hover:bg-[#0040A6]/40">
                                                    </div>

                                                </div>

                                            <?php endif; ?>

                                        </div>
                                    
                                    </div>

                                </div>

                            </div>

                        <?php endforeach; ?>

                        <?php if ($userRole === 'instructor'): ?>

                            <div
                                data-create-learning-unit-button
                                data-module-id="<?= esc($module['id']) ?>"
                                class="group flex cursor-pointer items-center gap-3 px-2 py-5 transition-all duration-200">

                                <div
                                    class="h-px flex-1 rounded-full bg-gray-200 transition-all duration-200 group-hover:bg-[#0040A6]/40">
                                </div>

                                <div
                                    class="flex h-11 w-11 items-center justify-center rounded-full border-2 border-dashed border-gray-300 bg-white shadow-sm transition-all duration-200 group-hover:scale-110 group-hover:border-[#0040A6] group-hover:bg-[#0040A6]">

                                    <svg
                                        xmlns="http://www.w3.org/2000/svg"
                                        class="h-5 w-5 text-gray-400 transition-all duration-200 group-hover:rotate-90 group-hover:text-white"
                                        fill="none"
                                        viewBox="0 0 24 24"
                                        stroke="currentColor"
                                        stroke-width="2">

                                        <path
                                            stroke-linecap="round"
                                            stroke-linejoin="round"
                                            d="M12 5v14M5 12h14"/>

                                    </svg>

                                </div>

                                <span
                                    class="max-w-0 overflow-hidden whitespace-nowrap text-sm font-medium text-[#0040A6]
                                        opacity-0 transition-all duration-200
                                        group-hover:max-w-[180px] group-hover:opacity-100">
                                    Add Unit
                                </span>

                                <div
                                    class="h-px flex-1 rounded-full bg-gray-200 transition-all duration-200 group-hover:bg-[#0040A6]/40">
                                </div>

                            </div>

                        <?php endif; ?>

                    </div>

                </div>

            </div>

        <?php endforeach; ?>

    </div>

</div>

<!-- modals -->
<?= $this->include('pages/courses/features/modules/partials/modals/module-editor') ?>
<?= $this->include('pages/courses/features/modules/partials/modals/module-delete') ?>
<?= $this->include('pages/courses/features/modules/partials/modals/learning-unit-editor') ?>
<?= $this->include('pages/courses/features/modules/partials/modals/learning-unit-delete') ?>
<?= $this->include('pages/courses/features/modules/partials/modals/learning-item-create') ?>
