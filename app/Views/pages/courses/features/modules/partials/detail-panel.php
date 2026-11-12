<?php if (!empty($currentAssessment)): ?>

    <?= $this->setData(['panelClass' => 'w-full xl:w-1/5', 'scoreBoxClass' => 'min-h-40 xl:aspect-square'])
        ->include('pages/courses/features/assessments/partials/score-panel') ?>

<?php endif; ?>
