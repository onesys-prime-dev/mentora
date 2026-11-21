<div class="flex flex-col gap-8">

    <!-- Instructions -->
    <div class="flex flex-col gap-3">

        <div class="flex items-center gap-3">
            <?= icon('info', 'h-5 w-5') ?>

            <span class="font-bold text-lg text-brand">
                Instructions
            </span>
        </div>

        <?= $this->setData(['instructionsEmptyText' => 'No instructions were provided for this assignment.'])
            ->include('pages/courses/features/assessments/partials/instructions-paragraph') ?>

    </div>

    <?php if (!empty($currentRubric['criteria'])): ?>

        <!-- Grading Rubrics -->
        <div class="flex flex-col gap-3">

            <div class="flex items-center gap-3">
                <?= icon('info', 'h-5 w-5') ?>

                <span class="font-bold text-lg text-brand">
                    Grading Rubrics
                </span>
            </div>

            <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3">

                <?php foreach ($currentRubric['criteria'] as $criterion): ?>

                    <div class="p-4 flex flex-col gap-2 rounded-xl bg-[#F2F2F2]">

                        <span class="font-bold text-md text-brand">
                            <?= esc((int) $criterion['max_points']) ?> pts
                        </span>

                        <span class="font-bold text-sm text-[#646464]">
                            <?= esc($criterion['criteria_title']) ?>
                        </span>

                        <span class="text-sm text-[#646464]">
                            <?= esc($criterion['description']) ?>
                        </span>

                    </div>

                <?php endforeach; ?>

            </div>

        </div>

    <?php endif; ?>

    <?php if (!\App\Services\Courses\CourseViewer::seesInstructorView($user)): ?>
        <?= $this->include('pages/courses/features/assessments/partials/student-group-card') ?>
    <?php endif; ?>

    <?php if (!empty($deanGroupView)): ?>

        <!-- Groups: the dean's read-only view of every group and grade (4E); grading.js fills it -->
        <section class="flex flex-col gap-3" aria-label="Groups" data-dean-groups>

            <div class="flex items-center gap-3">
                <?= icon('users', 'h-5 w-5') ?>

                <span class="font-bold text-lg text-brand">
                    Groups
                </span>
            </div>

            <div class="flex flex-col gap-4" data-group-grading data-assessment-id="<?= esc((string) $currentAssessment['id']) ?>">
                <?= view('pages/courses/features/assessments/partials/group-grading-frame', [], ['saveData' => false]) ?>
            </div>

        </section>

    <?php endif; ?>

    <?= $this->include('pages/courses/features/assessments/partials/assignment-work') ?>

</div>
