<?php
$userRole = esc($user->getGroups()[0]);
$isInstructor = $is_instructor;
// Course content can be changed by its instructor unless their institution took it away (courses.manage).
$canEditContent = $isInstructor && ! \App\Services\Courses\CourseAbilities::viewOnly(auth()->user(), 'content');

$formatDuration = static function (int $minutes): string {
    if ($minutes <= 0) {
        return '';
    }
    if ($minutes < 60) {
        return $minutes . ' minute' . ($minutes === 1 ? '' : 's') . ' to complete';
    }
    $hours = round($minutes / 60, 1);
    $hours = $hours == (int) $hours ? (int) $hours : $hours;
    return $hours . ' hour' . ($hours == 1 ? '' : 's') . ' to complete';
};

$resumeUnitId = $resume_item['learning_unit_id'] ?? null;
?>

<div class="p-10 flex flex-col gap-8">

    <?php $isDraft = $isInstructor && $module['status'] === 'draft'; ?>
    <?php $moduleIsDraft = $module['status'] !== 'published'; ?>

    <!-- top bar -->
    <div class="flex items-start justify-between gap-5 pb-6 border-b border-black/10">

        <div class="flex items-center gap-4">

            <button
                type="button"
                data-back-to-modules
                class="rounded-lg p-2 text-gray-500 transition hover:bg-gray-100"
                title="Back to Modules" aria-label="Back to Modules">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7" />
                </svg>
            </button>

            <div class="flex flex-col">
                <div class="flex items-center gap-2">
                    <span class="text-2xl font-bold text-brand"><?= esc($module['title']) ?></span>
                    <?php if ($isDraft): ?>
                        <span class="rounded-full border border-dashed border-[#B9B9B9] px-2.5 py-0.5 text-[10px] font-bold uppercase text-[#767676]">
                            Draft
                        </span>
                    <?php elseif ($canEditContent): ?>
                        <?= view('pages/courses/features/modules/partials/publish-chip', ['status' => 'published'], ['saveData' => false]) ?>
                    <?php endif; ?>
                </div>
                <span class="text-sm font-normal text-[#A3A3A3]">
                    <?php if ($module['display_number'] !== null): ?>Module <?= esc($module['display_number']) ?><?php endif; ?>
                    <?php if ($formatDuration((int) $module['duration_minutes']) !== ''): ?>
                        • <?= esc($formatDuration((int) $module['duration_minutes'])) ?>
                    <?php endif; ?>
                </span>
            </div>

        </div>

        <?php if ($canEditContent): ?>
            <?= view('pages/courses/features/modules/partials/module-actions-inline', ['module' => $module]) ?>
        <?php endif; ?>

    </div>

    <!-- progress -->
    <div class="flex flex-col gap-2">

        <?php if ($module['progress'] == 100): ?>

            <?= view('components/status-badge', ['tone' => 'success', 'icon' => 'check', 'label' => 'Completed'], ['saveData' => false]) ?>

        <?php elseif ($isInstructor && empty($module['has_roster'])): ?>

            <span class="self-start px-6 py-2 bg-[#E9E9EC] uppercase text-xs font-black text-[#767676] rounded-4xl">No students enrolled</span>

        <?php else: ?>

            <div class="flex items-center justify-between">
                <span class="text-sm font-bold text-[#646464]"><?= $isInstructor ? 'Class progress' : 'Your progress' ?></span>
                <span class="text-sm font-bold text-brand"><?= esc($module['progress']) ?>%</span>
            </div>

            <div class="h-2 w-full flex bg-[#E7E6E6] rounded-4xl">
                <div class="h-full bg-brand rounded-4xl transition-[width] duration-300" style="width: <?= esc($module['progress']) ?>%;"></div>
            </div>

        <?php endif; ?>

    </div>

    <?php if (trim((string) $module['description']) !== ''): ?>
        <p class="text-[#646464] leading-relaxed"><?= esc($module['description']) ?></p>
    <?php endif; ?>

    <?php if (!$isInstructor && $resume_item): ?>

        <div
            class="flex items-center justify-between gap-5 rounded-2xl border border-brand/20 bg-brand/5 px-6 py-5 cursor-pointer hover:bg-brand/10 transition"
            data-learning-item="<?= esc($resume_item['id']) ?>">

            <div class="flex items-center gap-4">
                <div class="h-10 w-10 flex items-center justify-center rounded-full bg-brand text-white">
                    <?= icon('start', 'h-5 w-5 text-current') ?>
                </div>
                <div class="flex flex-col">
                    <span class="text-xs font-bold uppercase text-brand">Continue where you left off</span>
                    <span class="text-lg font-semibold text-[#1A1C1E]"><?= esc($resume_item['title']) ?></span>
                    <span class="text-xs text-gray-500"><?= esc($resume_item['learning_unit_title']) ?></span>
                </div>
            </div>

            <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 text-brand" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7" />
            </svg>

        </div>

    <?php endif; ?>

    <!-- learning units -->
    <div class="flex flex-col gap-3" data-learning-unit-list>

        <?php foreach (($module['learning_units'] ?? []) as $learning_unit): ?>

            <?php
            $isResumeUnit = (int) $learning_unit['id'] === (int) $resumeUnitId;
            $startsOpen = $isInstructor || $isResumeUnit;
            $unitIsDraft = $isInstructor && $learning_unit['status'] === 'draft';
            $unitItems = $learning_unit['learning_items'] ?? [];
            $unitAllCompleted = !$isInstructor && $unitItems !== [] && !array_filter($unitItems, static fn ($i) => empty($i['is_completed']));
            $unitFullyLocked = !$isInstructor && $unitItems !== [] && !array_filter($unitItems, static fn ($i) => empty($i['is_locked']));
            ?>

            <div class="flex items-center p-1 py-2" data-learning-unit-row>

                <?php if ($canEditContent): ?>

                    <div data-learning-unit-drag-handle class="flex h-8 w-8 pl-1 cursor-grab items-center text-[#1A1C1E] active:cursor-grabbing">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-4" viewBox="0 0 16 24" fill="currentColor">
                            <circle cx="4" cy="4" r="2"/><circle cx="12" cy="4" r="2"/>
                            <circle cx="4" cy="12" r="2"/><circle cx="12" cy="12" r="2"/>
                            <circle cx="4" cy="20" r="2"/><circle cx="12" cy="20" r="2"/>
                        </svg>
                    </div>

                <?php endif; ?>

                <div
                    class="flex flex-col flex-1 rounded-2xl border <?= $isResumeUnit ? 'border-brand/30 border-l-4 border-l-brand' : ($unitIsDraft ? 'border-dashed border-[#B9B9B9]' : 'border-black/10') ?> <?= $unitFullyLocked ? 'bg-gray-50' : 'bg-white' ?> transition-colors hover:border-brand/30"
                    data-learning-unit-wrapper
                    data-learning-unit-id="<?= esc($learning_unit['id']) ?>">

                    <div class="flex justify-between items-center cursor-pointer px-5 py-4" data-learning-unit="<?= esc($learning_unit['id']) ?>">

                        <div class="flex items-center gap-4 min-w-0">
                            <div class="w-10 h-10 shrink-0 flex justify-center items-center rounded-full <?= $unitFullyLocked ? 'bg-gray-100' : 'bg-[#1E7A8C]/10' ?>">
                                <?= $unitFullyLocked ? icon('lock', 'h-4 w-4') : icon('learning-unit', 'h-5 w-5') ?>
                            </div>
                            <span class="text-lg truncate <?= $unitFullyLocked ? 'text-[#767676]' : 'text-[#1A1C1E]' ?>"><?= esc($learning_unit['title']) ?></span>

                            <?php if ($unitAllCompleted): ?>
                                <?= view('components/status-badge', ['tone' => 'success', 'icon' => 'check', 'label' => 'Completed'], ['saveData' => false]) ?>
                            <?php endif; ?>

                            <?php if ($unitIsDraft): ?>
                                <span class="shrink-0 rounded-full border border-dashed border-[#B9B9B9] px-2.5 py-0.5 text-[10px] font-bold uppercase text-[#767676]">
                                    Draft
                                </span>
                            <?php elseif ($canEditContent): ?>
                                <?= view('pages/courses/features/modules/partials/publish-chip', [
                                    'status' => $learning_unit['status'],
                                    'hiddenBy' => $moduleIsDraft ? 'module' : null,
                                ], ['saveData' => false]) ?>
                            <?php endif; ?>
                        </div>

                        <div class="flex items-center gap-2 shrink-0">

                            <div class="h-9 w-9 flex items-center justify-center">
                                <svg
                                    xmlns="http://www.w3.org/2000/svg"
                                    class="h-4 w-4 stroke-brand-dark transition-transform duration-300 <?= $startsOpen ? 'rotate-180' : '' ?>"
                                    fill="none" viewBox="0 0 24 24" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                                    data-learning-unit-arrow="<?= esc($learning_unit['id']) ?>">
                                    <path d="M6 9l6 6 6-6"/>
                                </svg>
                            </div>

                            <?php if ($canEditContent): ?>
                                <?= view('pages/courses/features/modules/partials/learning-unit-actions', ['module' => $module, 'learning_unit' => $learning_unit]) ?>
                            <?php endif; ?>

                        </div>

                    </div>

                    <div
                        data-learning-unit-content="<?= esc($learning_unit['id']) ?>"
                        class="flex transition-all duration-300 overflow-hidden <?= $startsOpen ? 'max-h-[3000px] opacity-100' : 'max-h-0 opacity-0' ?>">

                        <div class="w-full flex flex-col gap-1 px-3 pb-4" data-learning-unit-items>

                            <?php if ($unitItems === []): ?>
                                <p class="px-3 pb-2 text-sm text-[#767676] italic">No items yet</p>
                            <?php endif; ?>

                            <?php foreach (($learning_unit['learning_items'] ?? []) as $learning_item): ?>

                                <?php $itemBlocked = !$isInstructor && !empty($learning_item['is_locked']); ?>

                                <div class="flex items-center p-1" data-learning-item-row>

                                    <div
                                        class="group w-full flex items-center gap-4 px-5 py-3.5 rounded-xl border border-transparent <?= $itemBlocked ? 'cursor-not-allowed opacity-60' : 'cursor-pointer hover:border-brand/15 hover:bg-[#F4F6FB]' ?>"
                                        data-learning-item-wrapper
                                        data-learning-item="<?= esc($learning_item['id']) ?>"
                                        <?php if ($itemBlocked): ?>data-locked="1" aria-disabled="true"<?php endif; ?>>

                                        <?php if ($canEditContent): ?>

                                            <div data-learning-item-drag-handle class="flex h-8 w-8 pl-1 cursor-grab items-center text-[#1A1C1E] active:cursor-grabbing">
                                                <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-4" viewBox="0 0 16 24" fill="currentColor">
                                                    <circle cx="4" cy="4" r="2"/><circle cx="12" cy="4" r="2"/>
                                                    <circle cx="4" cy="12" r="2"/><circle cx="12" cy="12" r="2"/>
                                                    <circle cx="4" cy="20" r="2"/><circle cx="12" cy="20" r="2"/>
                                                </svg>
                                            </div>

                                        <?php else: ?>

                                            <div class="flex justify-center items-center px-2">

                                                <?php if ($learning_item['is_completed']): ?>
                                                    <div class="w-6 h-6 flex justify-center items-center border-2 border-[#7DB080] bg-[#E6F4EA] rounded-[999px]">
                                                        <?= icon('check', 'h-3.5 w-3.5') ?>
                                                    </div>
                                                <?php elseif (!empty($learning_item['is_locked'])): ?>
                                                    <div class="w-6 h-6 flex justify-center items-center border-2 border-[#999696] rounded-[999px]">
                                                        <?= icon('lock', 'h-3 w-3') ?>
                                                    </div>
                                                <?php else: ?>
                                                    <div class="w-6 h-6 flex justify-center items-center border-2 border-[#999696] rounded-[999px]"></div>
                                                <?php endif; ?>

                                            </div>

                                        <?php endif; ?>

                                        <div class="w-full flex justify-between items-center">
                                            <div class="flex flex-col gap-1">

                                                <div class="flex items-center gap-4">
                                                    <span class="text-md <?= !empty($learning_item['is_locked']) ? 'text-[#767676]' : '' ?>">
                                                        <?= esc($learning_item['title']) ?>
                                                    </span>

                                                    <?php if (!empty($learning_item['is_locked'])): ?>
                                                        <span class="text-xs text-[#767676]">
                                                            Complete <?= esc(implode(', ', array_map(static fn ($p) => '"' . $p['title'] . '"', $learning_item['locked_by']))) ?> first
                                                        </span>
                                                    <?php endif; ?>

                                                    <?php if ($isInstructor): ?>

                                                        <?php if (!empty($learning_item['dependency_labels'])): ?>
                                                            <span
                                                                title="Students must complete: <?= esc(implode(', ', $learning_item['dependency_labels'])) ?>"
                                                                class="inline-flex items-center gap-1 rounded-full bg-gray-100 px-2 py-0.5 text-[10px] font-medium text-gray-500">
                                                                <?= icon('lock', 'h-3 w-3 text-current') ?>
                                                                After <?= esc(implode(', ', $learning_item['dependency_labels'])) ?>
                                                            </span>
                                                        <?php endif; ?>

                                                        <?= view('pages/courses/features/modules/partials/publish-chip', [
                                                            'status' => $learning_item['status'],
                                                            'hiddenBy' => $moduleIsDraft ? 'module' : ($learning_unit['status'] !== 'published' ? 'unit' : null),
                                                        ], ['saveData' => false]) ?>

                                                    <?php endif; ?>

                                                </div>

                                                <div class="flex items-center gap-2">
                                                    <div class="flex justify-center items-center">
                                                        <?= learning_item_icon($learning_item['item_type'], 'h-4 w-4') ?>
                                                    </div>
                                                    <span class="text-sm text-[#646464]">
                                                        <?= $learning_item['item_type'] === 'pdf' ? 'PDF' : esc(ucfirst($learning_item['item_type'])) ?>
                                                    </span>
                                                </div>

                                            </div>

                                        </div>

                                        <?php if ($canEditContent): ?>
                                            <?= view('pages/courses/features/modules/partials/learning-item-actions', ['learning_item' => $learning_item]) ?>
                                        <?php endif; ?>

                                    </div>

                                </div>

                            <?php endforeach; ?>

                            <?php if ($canEditContent): ?>

                                <div
                                    data-create-learning-item-button
                                    data-module-id="<?= esc($module['id']) ?>"
                                    data-learning-unit-id="<?= esc($learning_unit['id']) ?>"
                                    class="group flex cursor-pointer items-center gap-3 px-2 py-5 transition-all duration-200">

                                    <div class="h-px flex-1 rounded-full bg-gray-200 transition-all duration-200 group-hover:bg-brand/40"></div>

                                    <div class="flex h-11 w-11 items-center justify-center rounded-full border-2 border-dashed border-gray-300 bg-white shadow-sm transition-all duration-200 group-hover:scale-110 group-hover:border-brand group-hover:bg-brand">
                                        <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 text-[#767676] transition-all duration-200 group-hover:rotate-90 group-hover:text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 5v14M5 12h14"/>
                                        </svg>
                                    </div>

                                    <span class="max-w-0 overflow-hidden whitespace-nowrap text-sm font-medium text-brand opacity-0 transition-all duration-200 group-hover:max-w-[180px] group-hover:opacity-100">
                                        Add Learning Item
                                    </span>

                                    <div class="h-px flex-1 rounded-full bg-gray-200 transition-all duration-200 group-hover:bg-brand/40"></div>

                                </div>

                            <?php endif; ?>

                        </div>

                    </div>

                </div>

            </div>

        <?php endforeach; ?>

        <?php if ($canEditContent): ?>

            <div data-create-learning-unit-button data-module-id="<?= esc($module['id']) ?>" class="group flex cursor-pointer items-center gap-3 px-2 py-5 transition-all duration-200">

                <div class="h-px flex-1 rounded-full bg-gray-200 transition-all duration-200 group-hover:bg-brand/40"></div>

                <div class="flex h-11 w-11 items-center justify-center rounded-full border-2 border-dashed border-gray-300 bg-white shadow-sm transition-all duration-200 group-hover:scale-110 group-hover:border-brand group-hover:bg-brand">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 text-[#767676] transition-all duration-200 group-hover:rotate-90 group-hover:text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 5v14M5 12h14"/>
                    </svg>
                </div>

                <span class="max-w-0 overflow-hidden whitespace-nowrap text-sm font-medium text-brand opacity-0 transition-all duration-200 group-hover:max-w-[180px] group-hover:opacity-100">
                    Add Unit
                </span>

                <div class="h-px flex-1 rounded-full bg-gray-200 transition-all duration-200 group-hover:bg-brand/40"></div>

            </div>

        <?php endif; ?>

        <?php if (($module['learning_units'] ?? []) === []): ?>
            <?= view('components/empty-state', ['icon' => 'folder', 'title' => 'No learning units yet'], ['saveData' => false]) ?>
        <?php endif; ?>

    </div>

</div>

<!-- modals -->
<?php if ($isInstructor): ?>
    <?= $this->include('pages/courses/features/modules/partials/modals/module-editor') ?>
    <?= $this->include('pages/courses/features/modules/partials/modals/module-delete') ?>
<?php endif; ?>
<?= $this->include('pages/courses/features/modules/partials/modals/learning-unit-editor') ?>
<?= $this->include('pages/courses/features/modules/partials/modals/learning-unit-delete') ?>
<?= $this->include('pages/courses/features/modules/partials/modals/learning-item-create') ?>
<?php if ($isInstructor): ?>
    <?= $this->include('pages/courses/features/modules/partials/modals/learning-item-dependencies') ?>
    <?= $this->include('pages/courses/features/modules/partials/modals/learning-item-delete') ?>
<?php endif; ?>
