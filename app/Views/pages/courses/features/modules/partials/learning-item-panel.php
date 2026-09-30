<?php $panelIsStudent = $user->inGroup('student'); ?>

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
                    class="h-full bg-[#0040A6] rounded-4xl transition-all duration-500"
                    data-module-progress-bar
                    style="width: <?= esc($module['progress']) ?>%;"></div>
            </div>

            <span
                class="text-xs text-[#5E5E5E]"
                data-module-progress-label>
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
                                (int) $item['id'] === (int) $currentItem['id'];

                            $isCompleted = $panelIsStudent && !empty($item['is_completed']);

                            $isLocked = !empty($item['is_locked']);

                            // Instructors see the lock on items that have dependencies.
                            $showLock = !$isCompleted && ($isLocked || (!$panelIsStudent && !empty($item['dependency_labels'])));
                        ?>

                            <?php
                            // A student can't open a locked item; completion.js
                            // makes it clickable again once it unlocks.
                            $blocked = $panelIsStudent && $isLocked && !$isCurrent;
                            ?>

                            <!-- item -->
                            <div
                                class="flex gap-3 items-center px-5 py-4 rounded-2xl border-b border-gray-100
                                <?= match (true) {
                                    $isCurrent => 'cursor-pointer bg-[#ECEEF4]',
                                    $blocked => 'cursor-not-allowed',
                                    default => 'cursor-pointer hover:bg-[#ECEEF4]',
                                } ?>"
                                data-learning-item="<?= esc($item['id']) ?>"
                                data-panel-item="<?= esc($item['id']) ?>"
                                <?php if ($blocked): ?>
                                    data-locked="1"
                                    aria-disabled="true"
                                    title="<?= esc('Complete ' . implode(', ', array_map(static fn ($p) => '“' . $p['title'] . '”', $item['locked_by'] ?? [])) . ' first', 'attr') ?>"
                                <?php endif; ?>>

                                <!-- status: completed ✓ / locked / not started — completion.js flips these live -->
                                <div
                                    data-item-status-circle
                                    class="w-6 h-6 shrink-0 flex justify-center items-center border-2 rounded-[999px]
                                    <?= $isCompleted ? 'border-[#7DB080] bg-[#E6F4EA]' : 'border-[#999696]' ?>"
                                    <?= !$panelIsStudent && $showLock ? 'title="Locked until: ' . esc(implode(', ', $item['dependency_labels']), 'attr') . '"' : '' ?>>

                                    <span data-item-status-check class="<?= $isCompleted ? '' : 'hidden' ?>">
                                        <?= icon('check', 'h-3.5 w-3.5') ?>
                                    </span>

                                    <span data-item-status-lock class="<?= $showLock ? '' : 'hidden' ?>">
                                        <?= icon('lock', 'h-3 w-3') ?>
                                    </span>

                                </div>

                                <div class="flex min-w-0 flex-col gap-0.5">

                                    <span
                                        class="text-sm
                                        <?= $isCurrent
                                            ? 'font-semibold text-[#0040A6]'
                                            : ($isLocked ? 'text-gray-400' : '') ?>"
                                        data-item-title>
                                        <?= esc($item['title']) ?>
                                    </span>

                                    <div class="flex items-center gap-2">

                                        <?= learning_item_icon($item['item_type'], 'h-3 w-3') ?>

                                        <span class="text-xs text-[#565656]">
                                            <?= $item['item_type'] === 'pdf' ? 'PDF' : esc(ucfirst($item['item_type'])) ?> • June 22, 2026
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
