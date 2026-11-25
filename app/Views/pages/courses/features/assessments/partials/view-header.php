<?php 
$userRole = \App\Services\Courses\CourseViewer::seesInstructorView($user) ? 'instructor' : esc($user->getGroups()[0]);
?>

<!-- header -->
    <?php
    // The author's avatar, built for this viewer: plain when they may not see the author (FR3.4).
    $authorId = (int) ($currentAssessment['created_by'] ?? 0);
    $authorAvatar = $authorId > 0 ? (new \App\Services\Profiles\AvatarData())->forViewer($user, [$authorId])[$authorId] ?? null : null;
    ?>
<header class="flex flex-col gap-2 py-2">
    
    <span class="wrap-break-word text-2xl font-bold text-brand sm:text-3xl">
        <?= esc($currentAssessment['title']) ?>
    </span>

    <div class="flex flex-wrap items-center gap-2">
        <div class="flex justify-center items-center">
            <?= learning_item_icon($currentAssessment['assessment_type'], 'h-4 w-4') ?>
        </div>

        <span class="capitalize text-sm text-[#646464]">
            <?= esc(str_replace('-', ' ', $currentAssessment['assessment_type'])) ?>

            •

            <?= esc($currentUnit['title']) ?>
        </span>

        <?php if ($userRole === 'student'): ?>
    
            <?php

                $isCompleted =
                    $currentAssessment['learning_completion']
                    &&
                    $currentAssessment['learning_completion']['status'] === 'completed';

            ?>

            <?php if ($isCompleted): ?>

                <?= view('components/status-badge', ['tone' => 'success', 'icon' => 'check', 'label' => 'Completed'], ['saveData' => false]) ?>

            <?php else: ?>

                <?= view('components/status-badge', ['tone' => 'info', 'label' => 'Not Yet Completed'], ['saveData' => false]) ?>

            <?php endif; ?>
        
        <?php elseif ($userRole === 'instructor'): ?>

            <span
                class="capitalize rounded-full px-3 py-1 text-xs font-black
                    <?= strtolower($currentAssessment['status']) === 'published'
                        ? 'bg-[#E0E5F2] text-brand-dark'
                        : 'bg-gray-100 text-gray-600' ?>">
                <?= esc($currentAssessment['status']) ?>
            </span>

        <?php endif; ?>

    </div>

    <div class="flex items-center gap-2">
        <?php if ($authorAvatar !== null): ?>
            <?= view('components/avatar', $authorAvatar + ['size' => 'sm', 'decorative' => true], ['saveData' => false]) ?>
        <?php endif; ?>

        <span class="capitalize text-sm text-[#646464]">
            <?= $authorAvatar !== null ? esc($authorAvatar['person']['name']) : 'Jalen Brunson' ?>  •  June 22, 2026  •  7:30AM 
        </span>
    </div>

</header>