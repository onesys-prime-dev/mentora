<div class="flex min-w-0 flex-1 flex-col p-10">

    <?= $this->include('pages/courses/features/assessments/partials/breadcrumb') ?>

    <div class="flex flex-1">

        <section class="flex min-w-0 flex-1 py-6 gap-12">

            <div class="flex min-w-0 flex-1 flex-col gap-5">

                <!-- header -->
                <?= $this->include('pages/courses/features/assessments/partials/view-header') ?>

                <!-- contents -->
                <?= $this->include('pages/courses/features/assessments/assessment-types/views/' . $currentAssessment['assessment_type']) ?>

            </div>

            <?= $this->include('pages/courses/features/assessments/partials/score-panel') ?>

        </section>

    </div>

    <?= $this->include('pages/courses/features/assessments/partials/navigations') ?>

</div>