<?php $panelIsStudent = \App\Services\Courses\CourseViewer::isStudentHere($user); ?>

<?php
// The aside runs the full height of the card (its tint and border reach the bottom however short the
// list). From lg up the inner column stays in view beside the item and scrolls on its own: the module
// heading and progress stay put, the list of units scrolls. --course-content-h is the height of the
// card that scrolls (set by the course page's script). Below the lock the window scrolls instead, so the column
// sticks under the app header and is at most the window's height less the header: its heading stays in view.
// Below lg the same outline is the panel of a "Contents" dropdown above the item (details-menu.js closes
// it on an outside click or Escape); from lg up app.css shows it whether or not the <details> is open.
?>
<aside class="flex shrink-0 flex-col bg-[#F9F9FC] border-[#9E9E9E] max-lg:rounded-t-3xl max-lg:border-b max-lg:px-5 max-lg:py-3 sm:max-lg:px-8 lg:w-80 lg:border-r" data-learning-item-panel>

<details class="group/contents relative lg:sticky lg:top-[var(--app-header-h,0px)] lock:top-0" data-menu data-contents>

    <summary class="flex min-h-11 cursor-pointer list-none items-center gap-3 rounded-xl border border-gray-200 bg-white px-4 py-2 text-sm lg:hidden [&::-webkit-details-marker]:hidden">
        <span class="shrink-0 font-semibold text-brand">Contents</span>
        <span class="min-w-0 flex-1 truncate text-[#5E5E5E]">Module <?= esc($module['order_index']) ?> · <?= esc($module['title']) ?></span>
        <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 shrink-0 stroke-brand-dark transition-transform duration-(--motion-base) group-open/contents:rotate-180" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <path d="M6 9l6 6 6-6"/>
        </svg>
    </summary>

<div class="flex flex-col bg-white max-lg:absolute max-lg:inset-x-0 max-lg:top-full max-lg:z-30 max-lg:mt-2 max-lg:max-h-[min(70dvh,32rem)] max-lg:overflow-y-auto max-lg:overscroll-contain max-lg:rounded-2xl max-lg:border max-lg:border-gray-200 max-lg:shadow-xl lg:max-h-[calc(100dvh-var(--app-header-h,0px))] lock:max-h-(--course-content-h,100vh) lg:bg-transparent" data-contents-panel>

    <div class="flex shrink-0 flex-col gap-1 p-5 lg:p-8 border-b border-[#9E9E9E]">

        <span class="text-xl text-brand font-bold">
            Module <?= esc($module['order_index']) ?>
        </span>

        <span class="text-md text-[#646464] font-bold">
            <?= esc($module['title']) ?>
        </span>

        <div class="w-full flex items-center gap-3 mt-2">
            <div class="h-2 w-full flex bg-[#E7E6E6] rounded-4xl">
                <div
                    class="h-full bg-brand rounded-4xl transition-all duration-500"
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

    <?php // Focusable so a keyboard can scroll the list (its item rows are not focusable). ?>
    <div tabindex="0" class="flex p-4 lg:min-h-0 lg:flex-1 lg:overflow-y-auto lg:overscroll-contain focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-brand/50">

        <div class="w-full flex flex-col gap-4">

            <?php foreach ($module['learning_units'] as $unit): ?>

                <!-- unit -->
                <div class="flex flex-col">

                    <!-- header -->
                    <div
                        class="w-full flex justify-between items-center py-2 cursor-pointer pointer-coarse:min-h-11"
                        data-learning-unit="<?= esc($unit['id']) ?>">

                        <span class="text-sm font-bold text-[#646464]/50">
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
                                            ? 'font-semibold text-brand'
                                            : ($isLocked ? 'text-[#767676]' : '') ?>"
                                        data-item-title>
                                        <?= esc($item['title']) ?>
                                    </span>

                                    <div class="flex items-center gap-2">

                                        <?= learning_item_icon($item['item_type'], 'h-3 w-3') ?>

                                        <span class="text-xs text-[#646464]">
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

</div>

</details>

</aside>
