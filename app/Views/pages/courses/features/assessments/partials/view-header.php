<?php 
$userRole = esc($user->getGroups()[0]);
?>

<!-- header -->
<header class="flex flex-col gap-2 py-2">
    
    <span class="text-3xl font-bold text-[#0040A6]">
        <?= esc($currentAssessment['title']) ?>
    </span>

    <?php
        $assessmentTypeIcon = is_file(FCPATH . 'assets/images/icons/' . $currentAssessment['assessment_type'] . '.png')
            ? $currentAssessment['assessment_type']
            : 'assessment';
    ?>

    <div class="flex items-center gap-2">
        <div class="flex justify-center items-center">
            <img
                class="h-4 w-auto"
                src="<?= base_url('assets/images/icons/' . esc($assessmentTypeIcon) . '.png') ?>" alt="">
        </div>

        <span class="capitalize text-sm text-[#565656]">
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

                <span class="px-3 py-1 rounded-full bg-[#E6F4EA] text-[#5C9F72] text-xs font-black">
                    Completed
                </span>

            <?php else: ?>

                <span class="px-3 py-1 rounded-full bg-[#E0E5F2] text-[#002068] text-xs font-black">
                    Not Yet Completed
                </span>

            <?php endif; ?>
        
        <?php elseif ($userRole === 'instructor'): ?>

            <span
                class="capitalize rounded-full px-3 py-1 text-xs font-black
                    <?= strtolower($currentAssessment['status']) === 'published'
                        ? 'bg-[#E0E5F2] text-[#002068]'
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

        <span class="capitalize text-sm text-[#565656]">
            Jalen Brunson  •  June 22, 2026  •  7:30AM 
        </span>
    </div>

</header>