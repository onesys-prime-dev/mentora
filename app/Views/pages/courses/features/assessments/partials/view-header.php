<?php 
$userRole = esc($user->getGroups()[0]);
?>

<!-- header -->
<header class="flex flex-col gap-2 py-2">
    
    <span class="text-3xl font-bold text-brand">
        <?= esc($currentAssessment['title']) ?>
    </span>

    <div class="flex items-center gap-2">
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