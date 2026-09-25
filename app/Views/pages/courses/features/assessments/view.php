<div class="flex min-w-0 flex-1 flex-col p-10">

    <?= $this->include('pages/courses/features/assessments/partials/breadcrumb') ?>

    <div class="flex flex-1">

        <section class="flex min-w-0 flex-1 py-6 gap-12">

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
                <?= $this->include('pages/courses/features/assessments/partials/score-panel') ?>
            <?php endif; ?>

        </section>

    </div>

    <?= $this->include('pages/courses/features/assessments/partials/navigations') ?>

</div>