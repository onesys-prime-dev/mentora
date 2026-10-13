<?php
$userRole = esc($user->getGroups()[0]);
$isInstructor = $userRole === 'instructor';

$moduleCount = count($modules);

$typeLabels = [
    'video' => ['Video', 'Videos'],
    'reading' => ['Reading', 'Readings'],
    'pdf' => ['PDF', 'PDFs'],
    'link' => ['Link', 'Links'],
    'quiz' => ['Quiz', 'Quizzes'],
    'assignment' => ['Assignment', 'Assignments'],
    'discussion' => ['Discussion', 'Discussions'],
];

// One consistent accent for every "What's included" pill — a touch
// lighter than the card background so it reads as a soft chip, not a
// strong color block.
$typeAccent = ['bg' => 'bg-brand/5', 'text' => 'text-brand'];

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
?>

<div class="p-10 flex flex-col gap-10">

    <div class="flex flex-col gap-3">

        <?php
        ob_start();
        if ($isInstructor): ?>
            <button
                class="inline-flex items-center gap-2 rounded-full bg-brand px-5 py-2 text-sm font-semibold text-white shadow transition-colors duration-300 hover:bg-brand-dark"
                data-create-module-button>

                <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 5v14M5 12h14" />
                </svg>

                <span>Add Module</span>

            </button>
        <?php endif;
        $headerActions = ob_get_clean();
        ?>

        <?= view('components/page-header', ['icon' => 'modules', 'title' => 'Course Modules', 'actions' => $headerActions], ['saveData' => false]) ?>

        <?php if ($moduleCount > 0): ?>
            <p class="text-[#646464] font-bold text-lg py-4">
                There <?= $moduleCount === 1 ? 'is' : 'are' ?> <?= esc($moduleCount) ?> module<?= $moduleCount === 1 ? '' : 's' ?> in this course
            </p>
        <?php endif; ?>

        <?php if (trim((string) $course_description) !== ''): ?>
            <p class="text-[#646464]/80 leading-relaxed">
                <?= esc($course_description) ?>
            </p>
        <?php endif; ?>

    </div>

    <!-- modules -->
    <div id="modules-container" class="flex flex-col gap-5">

        <?php foreach ($modules as $module): ?>

            <div class="flex items-center p-1" data-module-wrapper data-module-id="<?= esc($module['id']) ?>">

                <?php if ($isInstructor): ?>

                    <div data-module-card-drag-handle class="flex h-8 w-8 pl-1 cursor-grab items-center text-[#1A1C1E] active:cursor-grabbing">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-4" viewBox="0 0 16 24" fill="currentColor">
                            <circle cx="4" cy="4" r="2"/><circle cx="12" cy="4" r="2"/>
                            <circle cx="4" cy="12" r="2"/><circle cx="12" cy="12" r="2"/>
                            <circle cx="4" cy="20" r="2"/><circle cx="12" cy="20" r="2"/>
                        </svg>
                    </div>

                <?php endif; ?>

                <?php $isDraft = $isInstructor && $module['status'] === 'draft'; ?>

                <div class="group flex flex-col flex-1 bg-white border <?= $isDraft ? 'border-dashed border-[#B9B9B9]' : 'border-black/10' ?> rounded-2xl transition-colors" data-module-card>

                    <!-- header: chevron toggles the panel below, no navigation -->
                    <div class="flex justify-between items-center gap-5 p-6 cursor-pointer rounded-2xl focus-within:ring-2 focus-within:ring-brand/30" data-module="<?= esc($module['id']) ?>">

                        <div class="flex justify-center items-center">

                            <?php if ($module['progress'] == 100): ?>
                                <div class="w-15 h-15 flex justify-center items-center bg-[#E8F5E9] rounded-[999px]">
                                    <?= icon('check', 'h-7 w-7') ?>
                                </div>
                            <?php else: ?>
                                <div class="w-15 h-15 flex justify-center items-center bg-[#E0E5F2] rounded-[999px]">
                                    <?= icon('in-progress', 'h-8 w-8') ?>
                                </div>
                            <?php endif; ?>

                        </div>

                        <div class="flex flex-col flex-1">

                            <div class="flex flex-1 justify-between items-center gap-4">

                                <div class="flex flex-col flex-1">

                                    <div class="flex items-center gap-2">
                                        <span class="text-2xl font-bold text-brand">
                                            <?= esc($module['title']) ?>
                                        </span>
                                        <?php if ($isDraft): ?>
                                            <span class="rounded-full border border-dashed border-[#B9B9B9] px-2.5 py-0.5 text-[10px] font-bold uppercase text-[#767676]">
                                                Draft
                                            </span>
                                        <?php endif; ?>
                                    </div>

                                    <span class="text-sm font-normal text-[#A3A3A3]">
                                        <?php if ($module['display_number'] !== null): ?>
                                            Module <?= esc($module['display_number']) ?>
                                        <?php endif; ?>
                                        <?php if ($formatDuration((int) $module['duration_minutes']) !== ''): ?>
                                            • <?= esc($formatDuration((int) $module['duration_minutes'])) ?>
                                        <?php endif; ?>
                                    </span>

                                </div>

                                <?php if ($module['progress'] == 100): ?>

                                    <?= view('components/status-badge', ['tone' => 'success', 'icon' => 'check', 'label' => 'Completed'], ['saveData' => false]) ?>

                                <?php elseif ($isInstructor && empty($module['has_roster'])): ?>

                                    <span class="shrink-0 px-6 py-2 bg-[#E9E9EC] uppercase text-xs font-black text-[#767676] rounded-4xl">
                                        No students enrolled
                                    </span>

                                <?php elseif ($isInstructor): ?>

                                    <span class="shrink-0 px-6 py-2 bg-[#E0E5F2] uppercase text-xs font-black text-brand-dark rounded-4xl">
                                        Class progress <?= esc($module['progress']) ?>%
                                    </span>

                                <?php elseif ($module['progress'] > 0): ?>

                                    <span class="shrink-0 px-6 py-2 bg-[#E0E5F2] uppercase text-xs font-black text-brand-dark rounded-4xl">
                                        Progress <?= esc($module['progress']) ?>%
                                    </span>

                                <?php else: ?>

                                    <span class="shrink-0 px-6 py-2 bg-[#E9E9EC] uppercase text-xs font-black text-[#767676] rounded-4xl">
                                        Not yet started
                                    </span>

                                <?php endif; ?>

                            </div>

                            <?php if ($module['progress'] < 100): ?>
                                <div class="h-2 w-full mt-4 flex bg-[#E7E6E6] rounded-4xl">
                                    <div class="h-full bg-brand rounded-4xl transition-[width] duration-300" style="width: <?= esc($module['progress']) ?>%;"></div>
                                </div>
                            <?php endif; ?>

                        </div>

                        <div class="flex items-center gap-3 px-3">
                            <span class="text-xs font-semibold text-brand opacity-0 transition-opacity duration-200 group-hover:opacity-100" data-module-details-label="<?= esc($module['id']) ?>">
                                Module details
                            </span>
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 stroke-brand-dark transition-transform duration-300" data-module-arrow="<?= esc($module['id']) ?>" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M6 9l6 6 6-6"/>
                            </svg>
                        </div>

                        <?php if ($isInstructor): ?>
                            <?= view('pages/courses/features/modules/partials/module-actions', ['module' => $module]) ?>
                        <?php endif; ?>

                    </div>

                    <!-- expanded panel: description, what's included, go to module -->
                    <div class="max-h-0 overflow-hidden opacity-0 transition-all duration-300 ease-in-out px-10" data-module-content="<?= esc($module['id']) ?>">

                        <div class="pb-6 flex flex-col gap-4">

                            <span class="text-[#646464] leading-relaxed">
                                <?= esc($module['description']) ?>
                            </span>

                            <?php if ($module['item_type_counts'] !== []): ?>

                                <div class="flex flex-col gap-2">
                                    <span class="text-xs font-bold uppercase text-[#767676]">What's included</span>
                                    <div class="flex flex-wrap items-center gap-3">
                                        <?php foreach ($module['item_type_counts'] as $type => $count): ?>
                                            <?php
                                            [$singular, $plural] = $typeLabels[$type] ?? [ucfirst($type), ucfirst($type) . 's'];
                                            ?>
                                            <span class="inline-flex items-center gap-2 rounded-full <?= $typeAccent['bg'] ?> py-1 pl-1 pr-3 text-sm font-medium <?= $typeAccent['text'] ?>">
                                                <span class="flex h-6 w-6 items-center justify-center rounded-full bg-white/70">
                                                    <?= learning_item_icon($type, 'h-3.5 w-3.5 ' . $typeAccent['text']) ?>
                                                </span>
                                                <?= esc($count) ?> <?= esc($count === 1 ? $singular : $plural) ?>
                                            </span>
                                        <?php endforeach; ?>
                                    </div>
                                </div>

                            <?php elseif ($isInstructor): ?>

                                <span class="text-sm text-[#767676] italic">No content yet</span>

                            <?php endif; ?>

                            <button
                                type="button"
                                data-go-to-module="<?= esc($module['id']) ?>"
                                class="self-start mt-2 inline-flex items-center gap-2 rounded-full bg-brand px-5 py-2 text-sm font-semibold text-white shadow transition-colors duration-300 hover:bg-brand-dark">
                                Go to module
                                <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7" />
                                </svg>
                            </button>

                        </div>

                    </div>

                </div>

            </div>

        <?php endforeach; ?>

        <?php if ($modules === []): ?>
            <?= view('components/empty-state', [
                'icon' => 'folder',
                'title' => 'No modules yet',
                'text' => $isInstructor ? 'Create one with "Add Module" to get started.' : '',
            ], ['saveData' => false]) ?>
        <?php endif; ?>

    </div>

</div>

<!-- modals -->
<?= $this->include('pages/courses/features/modules/partials/modals/module-editor') ?>
<?= $this->include('pages/courses/features/modules/partials/modals/module-delete') ?>
