<div class="flex min-w-0 flex-1 flex-col p-5 sm:p-8 lg:p-10">

    <?= $this->include('pages/courses/features/assessments/partials/breadcrumb') ?>

    <div class="flex flex-1">

        <?php // Below lg the score panel stacks under the work; from lg up it sits beside it as before. ?>
        <section class="flex min-w-0 flex-1 flex-col gap-8 py-6 lg:flex-row lg:gap-12">

            <div class="flex min-w-0 flex-1 flex-col gap-5">

                <!-- header -->
                <?= $this->include('pages/courses/features/assessments/partials/view-header') ?>

                <!-- contents -->
                <?php if (!empty($assessmentLock)): ?>

                    <?= view('pages/courses/features/modules/learning-items/views/locked', ['lock' => $assessmentLock, 'lockLinks' => false]) ?>

                <?php else: ?>

                    <?= $this->include('pages/courses/features/assessments/assessment-types/views/' . strtolower($currentAssessment['assessment_type'])) ?>

                <?php endif; ?>

            </div>

            <?php if (empty($assessmentLock)): ?>
                <?= $this->setData(['panelClass' => 'w-full lg:w-1/5', 'scoreBoxClass' => 'min-h-40 lg:aspect-square'])
                    ->include('pages/courses/features/assessments/partials/score-panel') ?>
            <?php endif; ?>

        </section>

    </div>

    <?= $this->include('pages/courses/features/assessments/partials/navigations') ?>

</div>